<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only reconciliation for legacy SQL Server employee IDs vs current MySQL.
 * Never prints PII and never writes to either database.
 */
class PreviewLegacyEmployeeMergeCommand extends Command
{
    protected $signature = 'adms:preview-legacy-employee-merge';

    protected $description = 'Preview employee ID matches and importability between Att2000_V4 and MySQL (read-only)';

    public function handle(): int
    {
        if (! extension_loaded('pdo_sqlsrv') || ! extension_loaded('pdo_mysql')) {
            $this->error('Both pdo_sqlsrv and pdo_mysql extensions are required.');

            return self::FAILURE;
        }

        if (config('database.default') !== 'mysql') {
            $this->error('Refusing to run: default connection must remain mysql.');

            return self::FAILURE;
        }

        try {
            $source = DB::connection('legacy_sqlsrv');
            $target = DB::connection('mysql');

            // This is a read-only preview: no SQL Server biometric payloads or credentials are fetched.
            $sourceEmployees = $source->table('dbo.Employee')
                ->select(['BadgeNumber', 'NAME'])
                ->get();

            $currentIds = $target->table('employees')
                ->select('employee_id')
                ->pluck('employee_id')
                ->map(static fn ($value): string => trim((string) $value))
                ->all();

            $targetLookup = array_fill_keys($currentIds, true);
            $badgeCounts = [];
            $records = [];

            foreach ($sourceEmployees as $employee) {
                $badge = trim((string) ($employee->BadgeNumber ?? ''));
                $name = trim((string) ($employee->NAME ?? ''));

                if ($badge !== '') {
                    $badgeCounts[$badge] = ($badgeCounts[$badge] ?? 0) + 1;
                }

                $records[] = ['badge' => $badge, 'has_name' => $name !== ''];
            }

            $stats = [
                'SQL Server employee rows' => count($records),
                'MySQL employee rows' => count($currentIds),
                'Missing BadgeNumber (skip)' => 0,
                'Duplicate trimmed badge rows (manual review)' => 0,
                'Non-numeric badge codes (manual review for ZKTeco sync)' => 0,
                'Missing employee name (manual review)' => 0,
                'Already in MySQL (preserve existing)' => 0,
                'New valid numeric badge and name (candidate import)' => 0,
            ];

            foreach ($records as $record) {
                $badge = $record['badge'];

                if ($badge === '') {
                    $stats['Missing BadgeNumber (skip)']++;
                    continue;
                }

                if (($badgeCounts[$badge] ?? 0) > 1) {
                    $stats['Duplicate trimmed badge rows (manual review)']++;
                    continue;
                }

                if (! preg_match('/^[0-9]+$/D', $badge)) {
                    $stats['Non-numeric badge codes (manual review for ZKTeco sync)']++;
                    continue;
                }

                if (! $record['has_name']) {
                    $stats['Missing employee name (manual review)']++;
                    continue;
                }

                if (isset($targetLookup[$badge])) {
                    $stats['Already in MySQL (preserve existing)']++;
                    continue;
                }

                $stats['New valid numeric badge and name (candidate import)']++;
            }

            $this->info('Read-only employee merge preview — no records imported.');
            $this->table(
                ['Metric', 'Count'],
                array_map(
                    static fn (string $key, int $value): array => [$key, $value],
                    array_keys($stats),
                    array_values($stats)
                )
            );

            $this->warn('No employee records, attendance logs, or biometric templates have been imported.');
            $this->warn('New employee candidates are not automatically permitted to sync to devices.');
            $this->line('Review this report and validate a staging backup before implementing a write-enabled importer.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Unable to compare legacy and current employee records. Check database credentials and network connectivity.');

            return self::FAILURE;
        }
    }
}
