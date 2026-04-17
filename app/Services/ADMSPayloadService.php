<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Service for parsing ZKTeco ADMS protocol payloads
 * ADMS communicates using plain-text key=value format with CRLF separators
 */
class ADMSPayloadService
{
    const ADMS_DATE_FORMAT = 'Y-m-d H:i:s';

    /**
     * Parse ADMS raw payload into structured data
     * Format: key1=value1\r\nkey2=value2\r\n...
     */
    public function parsePayload(string $rawPayload): array
    {
        $lines = preg_split('/\r?\n/', trim($rawPayload));
        $parsed = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $parsed[trim($key)] = trim($value);
        }

        return $parsed;
    }

    /**
     * Parse attendance logs from ADMS payload
     * Expected format:
     * PIN=5\r\nUserID=1\r\nTime=2024-01-15 09:30:25\r\nVerifyFP=1\r\n...
     */
    public function parseAttendanceLogs(array $payload): array
    {
        $logs = [];

        // Check if this is a single record or multiple records
        if (isset($payload['PIN']) && isset($payload['Time'])) {
            $logs[] = $this->parseAttendanceRecord($payload);
        }

        return $logs;
    }

    /**
     * Parse a single attendance record
     */
    public function parseAttendanceRecord(array $record): array
    {
        $logType = $this->determineLogType($record);
        $verifyType = $this->determineVerifyType($record);

        return [
            'employee_code' => $record['PIN'] ?? $record['UserID'] ?? null,
            'log_type' => $logType,
            'verify_type' => $verifyType,
            'log_time' => $this->parseDateTime($record['Time'] ?? null),
            'device_log_id' => $record['LogID'] ?? null,
            'temperature' => $record['Temperature'] ?? null,
            'raw_payload' => $record,
        ];
    }

    /**
     * Generate ADMS response in plain-text format
     * Common responses: OK, ERROR, ERROR=message
     */
    public function generateResponse(string $status = 'OK', string $message = ''): string
    {
        if ($status === 'OK') {
            return "OK\r\n";
        }

        if ($message) {
            return "ERROR={$message}\r\n";
        }

        return "ERROR\r\n";
    }

    /**
     * Determine log type from payload
     */
    private function determineLogType(array $record): string
    {
        $verifyType = $record['Verify'] ?? $record['VerifyFP'] ?? $record['VerifyFace'] ?? null;

        // Check for specific event types if available
        if (isset($record['EventType'])) {
            $eventType = intval($record['EventType']);

            return match ($eventType) {
                0 => 'checkin',
                1 => 'checkout',
                2 => 'break',
                default => 'unknown',
            };
        }

        return 'unknown';
    }

    /**
     * Determine verification type from payload
     */
    private function determineVerifyType(array $record): string
    {
        // Check various verify fields for the verification method
        if (isset($record['VerifyFace']) && $record['VerifyFace']) {
            return 'face';
        }

        if (isset($record['VerifyFP']) && $record['VerifyFP']) {
            return 'fingerprint';
        }

        if (isset($record['VerifyID']) && $record['VerifyID']) {
            return 'rfid';
        }

        if (isset($record['VerifyTemp']) && $record['VerifyTemp']) {
            return 'temp';
        }

        if (isset($record['VerifyPwd']) && $record['VerifyPwd']) {
            return 'password';
        }

        return 'unknown';
    }

    /**
     * Parse date from ADMS format to Carbon instance
     */
    private function parseDateTime(?string $dateString)
    {
        if (! $dateString) {
            return now();
        }

        try {
            return Carbon::createFromFormat(self::ADMS_DATE_FORMAT, $dateString);
        } catch (\Exception) {
            return now();
        }
    }

    /**
     * Extract employee code from various possible fields
     */
    public function extractEmployeeCode(array $record): ?string
    {
        return $record['PIN'] ?? $record['UserID'] ?? $record['EmployeeID'] ?? null;
    }

    /**
     * Validate ADMS payload integrity
     */
    public function validatePayload(array $payload): array
    {
        $errors = [];

        if (empty($payload)) {
            $errors[] = 'Empty payload';

            return ['valid' => false, 'errors' => $errors];
        }

        // Check for minimum required fields for attendance log
        if (! isset($payload['PIN']) && ! isset($payload['UserID'])) {
            $errors[] = 'Missing employee identifier (PIN or UserID)';
        }

        if (! isset($payload['Time'])) {
            $errors[] = 'Missing timestamp';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Get device info from cdata payload
     * Used when device sends its info via /iclock/cdata
     */
    public function parseDeviceInfo(array $payload): array
    {
        return [
            'sn' => $payload['SN'] ?? null,
            'ip_address' => $payload['IPAddress'] ?? null,
            'firmware_version' => $payload['FirmwareVersion'] ?? null,
            'hardware_version' => $payload['HardwareVersion'] ?? null,
            'device_type' => $this->determineDeviceType($payload),
            'capacity' => intval($payload['Capacity'] ?? 0),
        ];
    }

    /**
     * Determine device type from capabilities
     */
    private function determineDeviceType(array $payload): string
    {
        $hasFace = isset($payload['Face']) && $payload['Face'];
        $hasFP = isset($payload['Fingerprint']) && $payload['Fingerprint'];
        $hasRFID = isset($payload['RFID']) && $payload['RFID'];

        if ($hasFace && ($hasFP || $hasRFID)) {
            return 'mixed';
        }

        if ($hasFace) {
            return 'face';
        }

        if ($hasFP) {
            return 'fingerprint';
        }

        if ($hasRFID) {
            return 'rfid';
        }

        return 'mixed';
    }
}
