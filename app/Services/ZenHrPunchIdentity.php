<?php

namespace App\Services;

/**
 * Canonical identity for a single attendance punch bound for ZenHR.
 * The terminal, its physical branch, and its In/Out status are irrelevant.
 * Preserve leading zeros in the employee number.
 */
final class ZenHrPunchIdentity
{
    public static function key(string $employeeNumber, string $localTime): ?string
    {
        $number = trim($employeeNumber);
        $time = trim($localTime);
        if ($number === '' || str_starts_with($number, '[')
            || ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $time)) {
            return null;
        }

        [$date, $clock] = explode(' ', $time, 2);
        [$year, $month, $day] = array_map('intval', explode('-', $date));
        [$hour, $minute, $second] = array_map('intval', explode(':', $clock));
        if (! checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        return hash('sha256', "zenhr-punch-v1\0{$number}\0{$time}");
    }
}
