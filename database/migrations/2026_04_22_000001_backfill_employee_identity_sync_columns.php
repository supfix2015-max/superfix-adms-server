<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }

        DB::table('employees')
            ->select('id', 'employee_id', 'uid', 'sync_uid', 'name', 'sync_name')
            ->orderBy('id')
            ->chunkById(200, function ($employees): void {
                foreach ($employees as $employee) {
                    $normalizedUid = $this->normalizeUid($employee);
                    $normalizedName = $this->normalizeName($employee);
                    $updates = [];

                    if (($employee->uid ?? null) !== $normalizedUid) {
                        $updates['uid'] = $normalizedUid;
                    }

                    if (($employee->sync_uid ?? null) !== $normalizedUid) {
                        $updates['sync_uid'] = $normalizedUid;
                    }

                    if (($employee->name ?? null) !== $normalizedName && $normalizedName !== null) {
                        $updates['name'] = $normalizedName;
                    }

                    if (($employee->sync_name ?? null) !== $normalizedName && $normalizedName !== null) {
                        $updates['sync_name'] = $normalizedName;
                    }

                    if ($updates === []) {
                        continue;
                    }

                    $updates['updated_at'] = now();

                    DB::table('employees')
                        ->where('id', $employee->id)
                        ->update($updates);
                }
            });
    }

    public function down(): void
    {
        // No-op. This migration only normalizes existing employee rows.
    }

    private function normalizeUid(object $employee): ?string
    {
        foreach ([$employee->sync_uid ?? null, $employee->uid ?? null, $employee->employee_id ?? null] as $candidate) {
            $candidate = preg_replace('/[^0-9]/', '', trim((string) $candidate));

            if ($candidate !== '') {
                return substr($candidate, 0, 32);
            }
        }

        return null;
    }

    private function normalizeName(object $employee): ?string
    {
        foreach ([$employee->name ?? null, $employee->sync_name ?? null] as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate !== '') {
                return substr($candidate, 0, 255);
            }
        }

        return null;
    }
};
