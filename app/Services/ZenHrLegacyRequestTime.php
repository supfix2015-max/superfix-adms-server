<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * The original BioWeb MySettings.TimezoneOffset is empty.
 *
 * ZenHR's Superfix branch (17787) is configured to Abu Dhabi. A live
 * comparison showed +0400 timestamps displayed 4 hours earlier in
 * ZenHR's attendance UI. Preserve the local SQL wall-clock value,
 * matching the original integration, instead of sending an offset.
 *
 * This changes ONLY future POST payload formatting; it cannot repair
 * rows already accepted by ZenHR.
 */
final class ZenHrLegacyRequestTime
{
    public static function fromSqlLocal(string $time): string
    {
        $time = trim($time);
        $date = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $time, 'Asia/Dubai');

        if ($date === false || $date->format('Y-m-d H:i:s') !== $time) {
            throw new RuntimeException('Invalid original local fingerprint timestamp.');
        }

        return $date->format('Y-m-d H:i:s');
    }
}
