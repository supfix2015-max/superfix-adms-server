<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only legacy ZKTeco schema and import-readiness audit.
 *
 * Does not export employee identifiers, biometric payloads, or credentials.
 * Does not write to the legacy SQL Server or the Laravel database.
 */
class AuditLegacyAttendanceCommand extends Command
{
    protected $signature = 'adms:audit-legacy-attendance';

    protected $description = 'Audit the legacy SQL Server attendance schema and key mappings without changing data';

    public function handle(): int
    {
        if (! extension_loaded('pdo_sqlsrv')) {
            $this->error('The pdo_sqlsrv PHP extension is unavailable in this container.');
            return self::FAILURE;
        }

        $expected = [
            'Employee' => ['Id', 'BadgeNumber', 'NAME', 'DepartmentId', 'CardNo', 'LastDeviceSN'],
            'Attendance' => ['Id', 'BadgeNumber', 'VerifyTime', 'DeviceSerialNumber', 'VerifyType', 'Status'],
            'EmployeeTemplate' => ['Id', 'BadgeNumber', 'TemplateType', 'TemplateIndex', 'Template', 'Format', 'MajorVer', 'MinorVer'],
            'Device' => ['Id', 'DeviceSerialNumber', 'DeviceAliasName', 'fp_alg_ver', 'push_protocol'],
        ];

        try {
            $db = DB::connection('legacy_sqlsrv');
            $db->getPdo();

            $schema = $db->select(
                "SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME IN ('Employee','Attendance','EmployeeTemplate','Device')"
            );

            $available = [];
            foreach ($schema as $row) {
                $available[strtolower((string) $row->TABLE_NAME)][] = strtolower((string) $row->COLUMN_NAME);
            }

            $missing = [];
            foreach ($expected as $table => $columns) {
                foreach ($columns as $column) {
                    if (! in_array(strtolower($column), $available[strtolower($table)] ?? [], true)) {
                        $missing[] = "{$table}.{$column}";
                    }
                }
            }

            if ($missing !== []) {
                $this->error('Expected columns missing: '.implode(', ', $missing));
                $this->warn('No import has been attempted. Check the source database schema.');
                return self::FAILURE;
            }

            $this->info('Legacy database connection and expected schema validated (read-only).');

            $countRows = $db->select(
                "SELECT t.name AS TableName, SUM(p.rows) AS TotalRows
                 FROM sys.tables t
                 JOIN sys.partitions p ON t.object_id = p.object_id
                 WHERE p.index_id IN (0, 1)
                   AND t.name IN ('Employee','Attendance','EmployeeTemplate','Device')
                 GROUP BY t.name
                 ORDER BY t.name"
            );

            $this->table(
                ['Source table', 'Approximate rows'],
                array_map(static fn (object $r): array => [$r->TableName, (int) $r->TotalRows], $countRows)
            );

            $integrity = $db->selectOne(
                "SELECT
                     (SELECT COUNT_BIG(*) FROM dbo.Employee
                      WHERE BadgeNumber IS NULL OR LTRIM(RTRIM(BadgeNumber)) = '') AS MissingEmployeeBadges,
                     (SELECT COUNT_BIG(*) FROM (
                         SELECT BadgeNumber FROM dbo.Employee
                         WHERE BadgeNumber IS NOT NULL
                         GROUP BY BadgeNumber HAVING COUNT_BIG(*) > 1
                     ) duplicates) AS DuplicateEmployeeBadgeGroups,
                     (SELECT MAX(VerifyTime) FROM dbo.Attendance) AS LastAttendance"
            );

            $lastAttendance = $integrity->LastAttendance;
            $lastAttendanceText = $lastAttendance instanceof \DateTimeInterface
                ? $lastAttendance->format('Y-m-d H:i:s')
                : (string) ($lastAttendance ?? 'not found');

            $this->table(
                ['Check', 'Result'],
                [
                    ['Employees without badge', (int) $integrity->MissingEmployeeBadges],
                    ['Duplicate employee badge groups', (int) $integrity->DuplicateEmployeeBadgeGroups],
                    ['Latest historical attendance', $lastAttendanceText],
                ]
            );

            $templateStats = $db->select(
                "SELECT TemplateType, [Format], COUNT_BIG(*) AS TotalRows
                 FROM dbo.EmployeeTemplate
                 GROUP BY TemplateType, [Format]
                 ORDER BY TemplateType, [Format]"
            );

            $this->line('Biometric template type/format counts (no template data displayed):');
            $this->table(
                ['TemplateType', 'Format', 'Rows'],
                array_map(
                    static fn (object $r): array => [$r->TemplateType ?? 'NULL', $r->Format ?? 'NULL', (int) $r->TotalRows],
                    $templateStats
                )
            );

            $this->warn('This is a schema/readiness audit only. No import or device sync was run.');
            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Legacy database audit failed. Check network, read-only credentials and TLS configuration.');
            return self::FAILURE;
        }
    }
}
