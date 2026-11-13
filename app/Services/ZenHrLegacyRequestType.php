<?php

namespace App\Services;

/**
 * Translate the original ZKTeco SQL status into the two ZenHR bulk API types.
 *
 * On this site's migrated dbo.Attendance table StatusId is NULL; Status
 * contains Check-In or Check-Out. Do not synthesize an alternating sequence
 * or treat break/overtime codes as normal in/out.
 */
final class ZenHrLegacyRequestType
{
    public static function fromFields(mixed $statusId, mixed $status): ?int
    {
        if ($statusId !== null) {
            if (! is_string($statusId) && ! is_int($statusId)) {
                return null;
            }

            $raw = trim((string) $statusId);
            if ($raw === '0') return 0;
            if ($raw === '1') return 1;
            // Unknown explicit numeric code is not interchangeable with
            // Check-In or Check-Out. It requires review.
            if ($raw !== '') return null;
        }

        if (! is_string($status)) {
            return null;
        }

        return match (strtolower(trim($status))) {
            'check-in', 'checkin' => 0,
            'check-out', 'checkout' => 1,
            default => null,
        };
    }
}
