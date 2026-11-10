<?php

namespace App\Console\Commands;

use App\Models\Employee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Import every historical Employee row into isolated staging MySQL.
 * No template/attendance import, no ADMS device commands, and no changes to
 * production MySQL. Missing/ambiguous device badges receive INTERNAL IDs only.
 */
class ImportLegacyEmployeesCommand extends Command
{
    protected $signature = 'adms:import-legacy-employees
                            {--apply : Insert into an isolated STAGING MySQL database only}
                            {--limit=10 : Maximum new rows to insert in apply mode (1-500)}';

    protected $description = 'Preview or import ALL legacy employees, including rows missing badges or names';

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
            $database = (string) config('database.connections.mysql.database');
            if (! in_array($environment, ['staging', 'testing', 'local'], true)
                || ! preg_match('/_staging$/i', $database)
                || $limit === false || $limit < 1 || $limit > 500) {
                $this->error('Writes blocked: APP_ENV must be staging/testing/local,');
                $this->error('MySQL database must end in _staging and --limit must be 1-500.');
                return self::FAILURE;
            }
        }

        try {
            $legacy = DB::connection('legacy_sqlsrv');
            $mysql = DB::connection('mysql');

            if (! Schema::connection('mysql')->hasTable('employees')
                || ! Schema::connection('mysql')->hasColumn('employees', 'raw_payload')
                || ! Schema::connection('mysql')->hasColumn('employees', 'sync_enabled')) {
                $this->error('Target MySQL staging employees schema is missing required columns.');
                return self::FAILURE;
            }

            $rows = $legacy->table('dbo.Employee')
                ->select(['Id', 'BadgeNumber', 'NAME'])
                ->orderBy('Id')
                ->get();

            $existingIds = $mysql->table('employees')
                ->pluck('employee_id')
                ->map(static fn ($id): string => trim((string) $id))
                ->all();
            $known = array_fill_keys($existingIds, true);
            $badgeCounts = [];
            $sourceIdCounts = [];

            foreach ($rows as $row) {
                $sourceId = (string) ($row->Id ?? '');
                $badge = trim((string) ($row->BadgeNumber ?? ''));
                $sourceIdCounts[$sourceId] = ($sourceIdCounts[$sourceId] ?? 0) + 1;
                if ($badge !== '') {
                    $badgeCounts[$badge] = ($badgeCounts[$badge] ?? 0) + 1;
                }
            }

            // A stable unique source key is essential for safe repeat imports.
            if (isset($sourceIdCounts['']) || max($sourceIdCounts ?: [0]) > 1) {
                $this->error('Duplicate or missing legacy Employee.Id detected; import stopped.');
                return self::FAILURE;
            }

            $stats = [
                'Legacy employee rows' => $rows->count(),
                'Existing MySQL employee rows' => count($existingIds),
                'Missing badge (still included)' => 0,
                'Duplicate badge rows (still included, internal ID)' => 0,
                'Non-numeric badge (still included, not device-ready)' => 0,
                'Missing name (placeholder used)' => 0,
                'Already in MySQL (preserved)' => 0,
                'New employee rows to import' => 0,
            ];

            $candidates = [];
            foreach ($rows as $row) {
                $legacyId = (string) $row->Id;
                $originalBadge = (string) ($row->BadgeNumber ?? '');
                $badge = trim($originalBadge);
                $originalName = trim((string) ($row->NAME ?? ''));
                $missingBadge = $badge === '';
                $duplicateBadge = $badge !== '' && ($badgeCounts[$badge] ?? 0) > 1;
                $tooLongBadge = strlen($badge) > 255;
                $missingName = $originalName === '';
                $nonNumericBadge = $badge !== '' && ! preg_match('/^[0-9]+$/D', $badge);

                if ($missingBadge) {
                    $stats['Missing badge (still included)']++;
                }
                if ($duplicateBadge) {
                    $stats['Duplicate badge rows (still included, internal ID)']++;
                }
                if ($nonNumericBadge) {
                    $stats['Non-numeric badge (still included, not device-ready)']++;
                }
                if ($missingName) {
                    $stats['Missing name (placeholder used)']++;
                }

                // Internal placeholders never become device-facing badges.
                $employeeCode = ($missingBadge || $duplicateBadge || $tooLongBadge)
                    ? 'LEGACY_REVIEW_ID_'.$legacyId
                    : $badge;

                // Keep existing rows untouched and make repeat imports idempotent.
                if (isset($known[$employeeCode])) {
                    $stats['Already in MySQL (preserved)']++;
                    continue;
                }

                $name = $missingName ? 'Legacy employee '.$legacyId.' (name unavailable)' : $originalName;
                $flags = array_keys(array_filter([
                    'missing_badge' => $missingBadge,
                    'duplicate_badge' => $duplicateBadge,
                    'non_numeric_badge' => $nonNumericBadge,
                    'badge_too_long' => $tooLongBadge,
                    'missing_name' => $missingName,
                ]));

                $candidates[] = [
                    'employee_id' => $employeeCode,
                    'name' => mb_substr($name, 0, 255),
                    'raw_payload' => json_encode([
                        'source' => 'Att2000_V4.Employee',
                        'legacy_id' => $legacyId,
                        'original_badge_number' => $originalBadge,
                        'review_flags' => $flags,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ];
                $stats['New employee rows to import']++;

                // Avoid creating two rows with the same candidate identifier.
                $known[$employeeCode] = true;
            }

            $this->info($apply ? 'STAGING-only import (no device sync)' : 'DRY RUN: no records will change');
            $this->table(
                ['Metric', 'Count'],
                array_map(
                    static fn (string $label, int $count): array => [$label, $count],
                    array_keys($stats), array_values($stats)
                )
            );

            if (! $apply) {
                $this->warn('No database records were written. Incomplete employees are INCLUDED in the planned import.');
                return self::SUCCESS;
            }

            $selected = array_slice($candidates, 0, $limit);
            $inserted = $mysql->transaction(static function () use ($mysql, $selected): int {
                $inserted = 0;
                foreach (array_chunk($selected, 50) as $chunk) {
                    $timestamp = now();
                    $values = array_map(static fn (array $row): array => [
                        'employee_id' => $row['employee_id'],
                        'name' => $row['name'],
                        'raw_payload' => $row['raw_payload'],
                        'status' => Employee::STATUS_INACTIVE,
                        'sync_enabled' => false,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ], $chunk);
                    $inserted += $mysql->table('employees')->insertOrIgnore($values);
                }
                return $inserted;
            });

            $this->info("Inserted {$inserted} employee(s) into STAGING MySQL; requested maximum {$limit}.");
            $this->warn('Imported employees are INACTIVE with sync disabled. No device commands were sent.');
            $this->warn('Internal placeholder IDs cannot be used for biometric matching until reviewed.');
            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Legacy employee import failed; inspect container logs for details.');
            return self::FAILURE;
        }
    }
}
