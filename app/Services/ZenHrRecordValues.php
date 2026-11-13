<?php

namespace App\Services;

/**
 * ZenHR sometimes returns an array/object for summary fields such as first_in,
 * last_out or card_id. Never cast these to strings: the value is ambiguous
 * and a cast raises "Array to string conversion".
 */
final class ZenHrRecordValues
{
    public static function scalar(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    public static function identities(array $record): array
    {
        $employee = $record['employee'] ?? null;
        $number = is_array($employee) ? ($employee['employment_number'] ?? null) : null;
        $values = [
            self::scalar($record['card_id'] ?? null),
            self::scalar($number),
        ];

        return array_values(array_unique(array_filter(
            $values, static fn (?string $value): bool => $value !== null
        )));
    }

    public static function summaryTimes(array $record): array
    {
        $times = [];
        foreach (['entry_time', 'exit_time', 'first_in', 'last_out'] as $name) {
            $value = self::scalar($record[$name] ?? null);
            if ($value !== null) {
                $times[] = $value;
            }
        }
        return $times;
    }

    public static function structuredFieldCount(array $record, array $fields): int
    {
        $count = 0;
        foreach ($fields as $field) {
            $value = $record[$field] ?? null;
            if (is_array($value) || is_object($value)) {
                $count++;
            }
        }
        return $count;
    }
}
