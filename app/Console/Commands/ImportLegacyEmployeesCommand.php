<?php

namespace App\Console\Commands;

use App\Models\Employee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Legacy employee import into an isolated staging MySQL DB.
 * The command defaults to a read-only dry-run. Any write requires --apply
 * AND explicit staging environment/database safeguards. Never queues ADMS
 * commands or imports fingerprint templates/attendance logs.
 */
class ImportLegacyEmployeesCommand extends Command
{
    protected $signature = 'adms:import-legacy-employees
                            {--apply : Write eligible employees to a STAGING MySQL DB only}
                            {--limit=10 : Maximum candidates to write (1-500) in apply mode}';

    protected $description = 'Preview or safely import legacy employees to a staging database (no device sync)';

    public function handle(): int
    {
        if (! extension_loaded('pdo_sqlsrv') || ! extension_loaded('pdo_mysql')) {
            $this->error('Required PDO extensions: pdo_sqlsrv and pdo_mysql.');
            return self::FAILURE;
        }

        if (config('database.default') !== 'mysql') {
            $this->error('Refusing to proceed: primary database must remain MySQL.');
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        if ($apply) {
            $environment = (string) config('app.env');
            $targetDatabase = (string) config('database.connections.mysql.database');

            if (! in_array($environment, ['staging', 'testing', 'local'], true)
                || ! preg_match('/_staging$/i', $targetDatabase)
                || $limit === false || $limit < 1 || $limit > 500) {
                $this->error('Writes blocked. APP_ENV must be staging/testing/local,');
                $this->error('MySQL DB name must end with _staging, and --limit must be 1-500.');
                return self::FAILURE;
            }
        }

        try {
            $legacy = DB::connection('legacy_sqlsrv');
            $mysql = DB::connection('mysql');

            $sourceRows = $legacy->table('dbo.Employee')
                ->select(['BadgeNumber', 'NAME'])
                ->get();

            if (! Schema::connection('mysql')->hasTable('employees')) {
                $this->error('MySQL employees table does not exist.');
                return self::FAILURE;
            }

            $known = $mysql->table('employees')
                ->pluck('employee_id')
                ->map(static fn (mixed $value): string => trim((string) $value))
                ->all();

            $knownMap = array_fill_keys($known, true);
            $badgeCounts = [];
            foreach ($sourceRows as $row) {
                $badge = trim((string) ($row->BadgeNumber ?? ''));
                if ($badge !== '') {
                    $badgeCounts[$badge] = ($badgeCounts[$badge] ?? 0) + 1;
                }
            }

            $stats = [
                'legacy_rows' => $sourceRows->count(),
                'missing_badge' => 0,
                'duplicate_badge_rows' => 0,
                'non_numeric_badge' => 0,
                'missing_name' => 0,
                'already_in_mysql' => 0,
                'eligible_new' => 0,
            ];

            $candidates = [];
            foreach ($sourceRows as $row) {
                $badge = trim((string) ($row->BadgeNumber ?? ''));
                $name = trim((string) ($row->NAME ?? ''));

                if ($badge === '') {
                    $stats['missing_badge']++;
                    continue;
                }
                if (($badgeCounts[$badge] ?? 0) !== 1) {
                    $stats['duplicate_badge_rows']++;
                    continue;
                }
                // Preserve the badge exactly, including leading zeros.
                if (! preg_match('/^[0-9]+$/D', $badge)) {
                    $stats['non_numeric_badge']++;
                    continue;
                }
                if ($name === '') {
                    $stats['missing_name']++;
                    continue;
                }
                if (isset($knownMap[$badge])) {
                    $stats['already_in_mysql']++;
                    continue;
                }

                $stats['eligible_new']++;
                $candidates[] = ['employee_id' => $badge, 'name' => $name];
            }

            $this->info($apply ? 'STAGING employee import mode' : 'Dry-run only: no database modifications');
            $this->table(
                ['Metric', 'Rows'],
                array_map(
                    static fn (string $label, int $value): array => [$label, $value],
                    array_keys($stats), array_values($stats)
                )
            );

            if (! $apply) {
                $this->line('To perform a limited import, first clone MySQL into a separate *_staging DB.');
                $this->warn('No employees, attendance, or biometric templates have been imported.');
                return self::SUCCESS;
            }

            $selected = array_slice($candidates, 0, $limit);
            $inserted = $mysql->transaction(static function () use ($mysql, $selected): int {
                $inserted = 0;
                foreach (array_chunk($selected, 50) as $chunk) {
                    $now = now();
                    $values = array_map(static fn (array $row): array => [
                        'employee_id' => $row['employee_id'],
                        'name' => $row['name'],
                        'status' => Employee::STATUS_INACTIVE,
                        'sync_enabled' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $chunk);

                    $inserted += $mysql->table('employees')->insertOrIgnore($values);
                }
                return $inserted;
            });

            $this->info("Inserted {$inserted} employee(s) into staging MySQL.");
            $this->warn('Imported employees are INACTIVE, device sync disabled; no device commands issued.');
            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Import preparation/execution failed. Review application logs for details.');
            return self::FAILURE;
        }
    }
}
