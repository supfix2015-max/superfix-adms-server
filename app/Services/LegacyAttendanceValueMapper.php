<?php

namespace App\Services;

use RuntimeException;

/**
 * Values written to the original Att2000_V4.Attendance nvarchar columns.
 * Keep punch direction (including the two overtime variants) intact.
 */
class LegacyAttendanceValueMapper
{
    public static function status(array $record): string
    {
        $raw = is_array($record['raw_payload'] ?? null) ? $record['raw_payload'] : [];
        $value = trim((string) ($raw['Status'] ?? $raw['InOutState'] ?? $raw['status'] ?? ''));

        // ZKTeco ATTLOG Status values are distinct events, not a boolean.
        $normalized = strtolower(trim($value));
        $mapping = [
            '0' => 'Check-In',
            '1' => 'Check-Out',
            '2' => 'Break-Out',
            '3' => 'Break-In',
            '4' => 'Overtime-In',
            '5' => 'Overtime-Out',
            'check-in' => 'Check-In',
            'checkin' => 'Check-In',
            'check-out' => 'Check-Out',
            'checkout' => 'Check-Out',
            'break-out' => 'Break-Out',
            'break-in' => 'Break-In',
            'overtime-in' => 'Overtime-In',
            'overtime-out' => 'Overtime-Out',
        ];

        if ($normalized !== '' && isset($mapping[$normalized])) {
            return $mapping[$normalized];
        }

        // Only unambiguous, verified normalized values are safe fallbacks.
        $fallback = strtolower(trim((string) ($record['log_type'] ?? '')));
        if (in_array($fallback, ['checkin', 'checkout'], true)) {
            return $fallback === 'checkin' ? 'Check-In' : 'Check-Out';
        }

        throw new RuntimeException('Unsupported or ambiguous ATTLOG status; original SQL Server was not modified.');
    }

    public static function verify(array $record): string
    {
        $raw = is_array($record['raw_payload'] ?? null) ? $record['raw_payload'] : [];
        $value = trim((string) ($raw['Verify'] ?? $raw['VerifyType'] ?? $raw['Method'] ?? ''));
        $normalized = strtolower($value);

        // Legacy database explicitly contains "2"; preserve it rather than
        // guessing its device-specific meaning.
        if ($normalized === '2') {
            return '2';
        }

        return match ($normalized) {
            '0', 'password', 'pwd' => 'Password',
            '1', 'fingerprint', 'fp' => 'FingerPrint',
            'rfid', 'card' => 'Card',
            '3', '4', '15', '17', 'face' => 'Face',
            '5', 'palm' => 'Palm',
            default => match (strtolower((string) ($record['verify_type'] ?? ''))) {
                'fingerprint' => 'FingerPrint',
                'password' => 'Password',
                'rfid' => '2',
                'face' => 'Face',
                'palm' => 'Palm',
                default => 'Unknown',
            },
        };
    }
}
