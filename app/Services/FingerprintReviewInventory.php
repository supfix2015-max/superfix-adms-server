<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Read-only inventory classification. A matching stored copy is not proof of
 * live device presence, and no classification authorizes a device command.
 */
final class FingerprintReviewInventory
{
    private static function slot(object $row, string $field): string
    {
        return json_encode([(string)$row->employee_code, (string)$row->{$field}], JSON_THROW_ON_ERROR);
    }

    private static function hash(object $row): string
    {
        return strtoupper(trim((string)$row->version_hash));
    }

    private static function valid(object $row): bool
    {
        return (bool)$row->has_template && $row->is_valid !== false
            && preg_match('/^\d{1,32}$/D', (string)$row->employee_code) === 1
            && preg_match('/^[0-9]$/D', (string)$row->template_index) === 1
            && preg_match('/^[A-F0-9]{64}$/D', self::hash($row)) === 1;
    }

    public static function classify(Collection $rows, Collection $jobs): array
    {
        $bySource = $jobs->groupBy('source_biometric_id');
        $bySlot = $jobs->groupBy(fn ($job) => self::slot($job, 'finger_slot'));
        $tabs = ['pending'=>[], 'sync'=>[], 'investigation'=>[], 'matching'=>[], 'all'=>[]];
        $labels = [];
        foreach ($rows->groupBy(fn ($row) => self::slot($row, 'template_index')) as $slot => $group) {
            $slotJobs = $bySlot->get($slot, collect());
            $versions = $group->filter(fn ($row) => self::valid($row))->groupBy(fn ($row) => self::hash($row));
            $hasDifferentCopies = $versions->count() > 1;
            foreach ($group->groupBy(fn ($row) => self::hash($row)) as $hash => $copies) {
                $hash = (string)$hash;
                $versionJobs = $slotJobs->filter(fn ($job) => self::hash($job) === $hash);
                $canonical = $copies->filter(fn ($row) => self::valid($row))
                    ->sortBy('id')->first();
                foreach ($copies as $row) {
                    $id = (int)$row->id;
                    $tabs['all'][] = $id;
                    $history = $bySource->get($id, collect());
                    $current = $history->filter(fn ($job) => self::hash($job) === $hash
                        && self::slot($job, 'finger_slot') === $slot);
                    $investigate = $history->contains(fn ($job) => in_array($job->state,
                        ['failed','unknown','conflict','stale'], true));
                    if ($history->contains(fn ($job) => (int)$job->target_device_id > 0)) {
                        $tabs['sync'][] = $id;
                    }
                    if (!self::valid($row)) {
                        $label = 'invalid';
                        $investigate = true;
                    } elseif ($current->isNotEmpty()) {
                        $label = 'reviewed';
                    } elseif ($versionJobs->isNotEmpty() || ($canonical && (int)$canonical->id !== $id)) {
                        $label = 'matching';
                        $tabs['matching'][] = $id;
                    } elseif ($hasDifferentCopies || $slotJobs->isNotEmpty()) {
                        $label = 'version_conflict';
                        $investigate = true;
                    } else {
                        $label = 'pending';
                        $tabs['pending'][] = $id;
                    }
                    if ($hasDifferentCopies) $investigate = true;
                    if ($investigate) $tabs['investigation'][] = $id;
                    $labels[$id] = $label;
                }
            }
        }
        return ['tabs'=>$tabs, 'labels'=>$labels];
    }
}
