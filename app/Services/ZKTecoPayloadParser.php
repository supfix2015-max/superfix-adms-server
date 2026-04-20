<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class ZKTecoPayloadParser
{
    public function parseMetadataPayload(string $payload): array
    {
        $pairs = [];

        foreach ($this->splitMetadataFragments($payload) as $fragment) {
            $fragment = trim((string) $fragment);
            if ($fragment === '' || ! str_contains($fragment, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $fragment, 2);
            $pairs[trim($key)] = $this->decodeValue($value);
        }

        return $pairs;
    }

    public function detectPayloadType(string $payload, ?string $table = null): string
    {
        $normalizedTable = strtoupper((string) $table);
        if ($normalizedTable === 'ATTLOG') {
            return 'attendance';
        }

        if ($normalizedTable === 'USERINFO') {
            return 'userinfo';
        }

        if (trim($payload) === '') {
            return 'options';
        }

        if ($this->parseAttendancePayload($payload)['records'] !== []) {
            return 'attendance';
        }

        if ($this->parseUserPayload($payload)['records'] !== []) {
            return 'userinfo';
        }

        if ($this->parseBiometricPayload($payload)['records'] !== []) {
            return 'biometric';
        }

        return $this->parseMetadataPayload($payload) !== [] ? 'metadata' : 'unknown';
    }

    public function parseAttendancePayload(string $payload): array
    {
        $records = [];
        $errors = [];
        $lineNumber = 0;

        foreach (preg_split('/\r?\n/', trim($payload)) as $line) {
            $lineNumber++;
            $line = trim((string) $line);

            if ($line === '') {
                continue;
            }

            if (str_contains($line, "\t") && ! str_contains($line, '=')) {
                $record = $this->parseTabAttendanceLine($line);

                if ($record === null) {
                    $errors[] = "Invalid tab attendance line {$lineNumber}";

                    continue;
                }

                $records[] = $record;
            }
        }

        foreach ($this->parseKeyValueBlocks($payload) as $block) {
            $record = $this->parseKeyValueAttendanceBlock($block);

            if ($record === null) {
                continue;
            }

            $records[] = $record;
        }

        if ($records === [] && trim($payload) !== '' && $this->parseMetadataPayload($payload) === []) {
            $errors[] = 'No attendance records were recognized';
        }

        return [
            'records' => $this->uniqueRecords($records),
            'errors' => array_values(array_unique($errors)),
        ];
    }

    public function parseUserPayload(string $payload): array
    {
        $records = [];
        $errors = [];
        $lineNumber = 0;

        foreach (preg_split('/\r?\n/', trim($payload)) as $line) {
            $lineNumber++;
            $line = trim((string) $line);

            if ($line === '') {
                continue;
            }

            if (str_contains($line, "\t") && ! str_contains($line, '=')) {
                $record = $this->parseTabUserLine($line);

                if ($record === null) {
                    $errors[] = "Invalid user line {$lineNumber}";

                    continue;
                }

                $records[] = $record;
            }
        }

        foreach ($this->parseKeyValueBlocks($payload) as $block) {
            $record = $this->parseKeyValueUserBlock($block);

            if ($record === null) {
                continue;
            }

            $records[] = $record;
        }

        if ($records === [] && trim($payload) !== '' && $this->parseMetadataPayload($payload) === []) {
            $errors[] = 'No user records were recognized';
        }

        return [
            'records' => $this->uniqueRecords($records),
            'errors' => array_values(array_unique($errors)),
        ];
    }

    public function parseBiometricPayload(string $payload): array
    {
        $records = [];
        $errors = [];

        foreach ($this->parseKeyValueBlocks($payload) as $block) {
            $record = $this->parseKeyValueBiometricBlock($block);

            if ($record === null) {
                continue;
            }

            $records[] = $record;
        }

        if ($records === [] && trim($payload) !== '' && $this->parseMetadataPayload($payload) === []) {
            $errors[] = 'No biometric records were recognized';
        }

        return [
            'records' => $this->uniqueRecords($records),
            'errors' => array_values(array_unique($errors)),
        ];
    }

    public function parseCommandReplyPayload(string $payload): array
    {
        $blocks = $this->parseKeyValueBlocks($payload);

        if ($blocks !== []) {
            return $blocks;
        }

        $pairs = $this->parseMetadataPayload($payload);

        return $pairs !== [] ? [$pairs] : [];
    }

    public function normalizeTimestamp(?string $timestamp): ?Carbon
    {
        $timestamp = trim((string) $timestamp);

        if ($timestamp === '') {
            return null;
        }

        if (ctype_digit($timestamp)) {
            $epoch = strlen($timestamp) >= 13
                ? (int) floor(((int) $timestamp) / 1000)
                : (int) $timestamp;

            return Carbon::createFromTimestamp($epoch, config('zkteco.device_timezone'))
                ->setTimezone(config('app.timezone'));
        }

        $formats = [
            'Y-m-d H:i:s',
            'Y/m/d H:i:s',
            'Y-m-d\TH:i:s',
            'Y-m-d H:i',
            'Y/m/d H:i',
            'YmdHis',
            'd/m/Y H:i:s',
            'd-m-Y H:i:s',
            'd/m/Y H:i',
            'd-m-Y H:i',
            'm/d/Y H:i:s',
            'm-d-Y H:i:s',
        ];

        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, $timestamp, config('zkteco.device_timezone'))
                    ->setTimezone(config('app.timezone'));
            } catch (\Throwable) {
            }
        }

        try {
            return Carbon::parse($timestamp, config('zkteco.device_timezone'))
                ->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseKeyValueBlocks(string $payload): array
    {
        $blocks = [];
        $current = [];

        foreach (preg_split('/\r?\n/', trim($payload)) as $line) {
            $line = trim((string) $line);

            if ($line === '') {
                if ($current !== []) {
                    $blocks[] = $current;
                    $current = [];
                }

                continue;
            }

            if (! str_contains($line, '=')) {
                continue;
            }

            $lineFragments = $this->splitLineFragments($line);
            $firstFragment = trim((string) ($lineFragments[0] ?? ''));
            $firstKey = str_contains($firstFragment, '=')
                ? trim(explode('=', $firstFragment, 2)[0])
                : null;

            if ($current !== [] && $this->isRecordBoundaryKey($firstKey)) {
                $blocks[] = $current;
                $current = [];
            }

            foreach ($lineFragments as $fragment) {
                $fragment = trim((string) $fragment);

                if ($fragment === '' || ! str_contains($fragment, '=')) {
                    continue;
                }

                [$key, $value] = explode('=', $fragment, 2);
                $key = trim($key);
                $value = $this->decodeValue($value);

                if (array_key_exists($key, $current)) {
                    $blocks[] = $current;
                    $current = [];
                }

                $current[$key] = $value;
            }
        }

        if ($current !== []) {
            $blocks[] = $current;
        }

        return array_values(array_filter($blocks));
    }

    private function isRecordBoundaryKey(?string $key): bool
    {
        if ($key === null) {
            return false;
        }

        $normalizedKey = strtolower(trim($key));

        return in_array($normalizedKey, [
            'pin',
            'user pin',
            'fp pin',
            'face pin',
            'userpic pin',
        ], true);
    }

    private function parseTabAttendanceLine(string $line): ?array
    {
        $parts = array_pad(explode("\t", $line), 9, null);
        $employeeCode = $this->sanitizeIdentifier($parts[0] ?? null);
        $timestamp = trim((string) ($parts[1] ?? ''));

        if ($employeeCode === null || $timestamp === '') {
            return null;
        }

        return [
            'employee_code' => $employeeCode,
            'uid' => null,
            'name' => null,
            'card_no' => null,
            'timestamp' => $timestamp,
            'status' => $this->normalizeScalar($parts[2] ?? null),
            'verify' => $this->normalizeScalar($parts[3] ?? null),
            'work_code' => $this->normalizeScalar($parts[4] ?? null),
            'device_log_id' => $this->normalizeScalar($parts[7] ?? null),
            'temperature' => null,
            'raw' => [
                'PIN' => $employeeCode,
                'Time' => $timestamp,
                'Status' => $this->normalizeScalar($parts[2] ?? null),
                'Verify' => $this->normalizeScalar($parts[3] ?? null),
                'WorkCode' => $this->normalizeScalar($parts[4] ?? null),
                'IDNum' => $this->normalizeScalar($parts[7] ?? null),
                'Type' => $this->normalizeScalar($parts[8] ?? null),
            ],
        ];
    }

    private function parseTabUserLine(string $line): ?array
    {
        $parts = array_pad(explode("\t", $line), 7, null);
        $employeeCode = $this->sanitizeIdentifier($parts[0] ?? null);

        if ($employeeCode === null) {
            return null;
        }

        return [
            'employee_code' => $employeeCode,
            'uid' => $this->sanitizeIdentifier($parts[6] ?? $parts[0] ?? null) ?? $employeeCode,
            'name' => $this->normalizeScalar($parts[1] ?? null),
            'card_no' => $this->normalizeScalar($parts[3] ?? null),
            'password' => null,
            'privilege' => null,
            'group_code' => null,
            'timezone_code' => null,
            'verification_mode' => null,
            'vice_card_no' => null,
            'valid_from' => null,
            'valid_until' => null,
            'raw' => [
                'PIN' => $employeeCode,
                'Name' => $this->normalizeScalar($parts[1] ?? null),
                'Card' => $this->normalizeScalar($parts[3] ?? null),
                'UID' => $this->sanitizeIdentifier($parts[6] ?? $parts[0] ?? null) ?? $employeeCode,
            ],
        ];
    }

    private function parseKeyValueAttendanceBlock(array $block): ?array
    {
        $employeeCode = $this->sanitizeIdentifier($this->lookup($block, [
            'PIN',
            'UserID',
            'EnrollNumber',
            'UID',
        ]));
        $timestamp = $this->lookup($block, ['Time', 'DateTime', 'RecordTime', 'Timestamp']);

        if ($employeeCode === null || $timestamp === null) {
            return null;
        }

        return [
            'employee_code' => $employeeCode,
            'uid' => $this->sanitizeIdentifier($this->lookup($block, ['UID'])),
            'name' => $this->normalizeScalar($this->lookup($block, ['Name'])),
            'card_no' => $this->normalizeScalar($this->lookup($block, ['Card', 'CardNo'])),
            'timestamp' => $timestamp,
            'status' => $this->normalizeScalar($this->lookup($block, ['Status', 'InOutState'])),
            'verify' => $this->normalizeScalar($this->lookup($block, ['Verify', 'VerifyType', 'Method'])),
            'work_code' => $this->normalizeScalar($this->lookup($block, ['WorkCode'])),
            'device_log_id' => $this->normalizeScalar($this->lookup($block, ['IDNum', 'LogID'])),
            'temperature' => $this->normalizeScalar($this->lookup($block, ['Temperature'])),
            'raw' => $block,
        ];
    }

    private function parseKeyValueUserBlock(array $block): ?array
    {
        $employeeCode = $this->sanitizeIdentifier($this->lookup($block, [
            'PIN',
            'USER PIN',
            'UserID',
            'EnrollNumber',
            'UID',
            'FP PIN',
            'FACE PIN',
            'USERPIC PIN',
        ]));

        if (
            $employeeCode === null
            || (
                $this->lookup($block, ['Name']) === null
                && $this->lookup($block, ['Card', 'CardNo']) === null
            )
        ) {
            return null;
        }

        return [
            'employee_code' => $employeeCode,
            'uid' => $this->sanitizeIdentifier($this->lookup($block, ['UID']) ?? $employeeCode) ?? $employeeCode,
            'name' => $this->normalizeScalar($this->lookup($block, ['Name'])),
            'card_no' => $this->normalizeScalar($this->lookup($block, ['Card', 'CardNo'])),
            'password' => $this->normalizeScalar($this->lookup($block, ['Passwd', 'Password'])),
            'privilege' => $this->normalizeScalar($this->lookup($block, ['Pri', 'Privilege'])),
            'group_code' => $this->normalizeScalar($this->lookup($block, ['Grp', 'Group'])),
            'timezone_code' => $this->normalizeScalar($this->lookup($block, ['TZ', 'Timezone'])),
            'verification_mode' => $this->normalizeScalar($this->lookup($block, ['Verify', 'VerifyType'])),
            'vice_card_no' => $this->normalizeScalar($this->lookup($block, ['ViceCard', 'ViceCardNo'])),
            'valid_from' => $this->normalizeScalar($this->lookup($block, ['StartDatetime', 'StartTime', 'ValidFrom'])),
            'valid_until' => $this->normalizeScalar($this->lookup($block, ['EndDatetime', 'EndTime', 'ValidUntil'])),
            'raw' => $block,
        ];
    }

    private function parseKeyValueBiometricBlock(array $block): ?array
    {
        $biometricType = $this->detectBiometricBlockType($block);
        if ($biometricType === null) {
            return null;
        }

        $employeeCode = $this->sanitizeIdentifier($this->lookup($block, [
            'FP PIN',
            'FACE PIN',
            'USERPIC PIN',
            'PIN',
        ]));

        if ($employeeCode === null) {
            return null;
        }

        $templateIndex = $this->normalizeScalar($this->lookup($block, ['FID', 'FingerID', 'FaceID', 'Index']));
        $fileName = $this->normalizeScalar($this->lookup($block, ['FileName', 'Filename']));
        $templateData = $this->normalizeScalar($this->lookup($block, ['TMP', 'Template', 'Content', 'Photo']));
        $dataSize = $this->normalizeDataSize($this->lookup($block, ['Size', 'DataSize', 'Length']));
        $isValid = $this->normalizeBooleanScalar($this->lookup($block, ['Valid', 'IsValid']));

        if ($templateIndex === null && $fileName === null && $templateData === null && $dataSize === null) {
            return null;
        }

        return [
            'employee_code' => $employeeCode,
            'biometric_type' => $biometricType,
            'template_index' => $templateIndex,
            'file_name' => $fileName,
            'is_valid' => $isValid,
            'data_size' => $dataSize,
            'template_data' => $templateData,
            'raw' => $block,
        ];
    }

    private function detectBiometricBlockType(array $block): ?string
    {
        $lowerCaseBlock = array_change_key_case($block, CASE_LOWER);
        $keys = array_keys($lowerCaseBlock);

        foreach ($keys as $key) {
            if (str_starts_with($key, 'fp pin')) {
                return 'fingerprint';
            }

            if (str_starts_with($key, 'face pin')) {
                return 'face';
            }

            if (str_starts_with($key, 'userpic pin')) {
                return 'photo';
            }
        }

        if (array_key_exists('tmp', $lowerCaseBlock) && array_key_exists('fid', $lowerCaseBlock)) {
            return 'fingerprint';
        }

        return null;
    }

    private function sanitizeIdentifier(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $value = preg_replace('/[^A-Za-z0-9._:-]/', '', $value);

        return $value !== '' ? substr($value, 0, 64) : null;
    }

    private function normalizeScalar(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $this->decodeValue($value);
    }

    private function normalizeBooleanScalar(mixed $value): ?bool
    {
        $value = $this->normalizeScalar($value);

        if ($value === null) {
            return null;
        }

        return match (strtolower($value)) {
            '1', 'true', 'yes', 'y' => true,
            '0', 'false', 'no', 'n' => false,
            default => null,
        };
    }

    private function normalizeDataSize(mixed $value): ?int
    {
        $value = $this->normalizeScalar($value);

        if ($value === null || preg_match('/^[0-9]+$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    private function decodeValue(string $value): string
    {
        return trim(rawurldecode($value));
    }

    private function splitMetadataFragments(string $payload): array
    {
        return preg_split('/[&\r\n\t]+/', trim($payload)) ?: [];
    }

    private function splitLineFragments(string $line): array
    {
        return preg_split('/(?:&|\t)+/', $line) ?: [];
    }

    private function lookup(array $block, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $block)) {
                return $block[$key];
            }
        }

        $lowerCaseBlock = array_change_key_case($block, CASE_LOWER);

        foreach ($keys as $key) {
            $normalizedKey = strtolower($key);

            if (array_key_exists($normalizedKey, $lowerCaseBlock)) {
                return $lowerCaseBlock[$normalizedKey];
            }
        }

        return null;
    }

    private function uniqueRecords(array $records): array
    {
        return Collection::make($records)
            ->unique(static fn (array $record): string => sha1(json_encode($record, JSON_THROW_ON_ERROR)))
            ->values()
            ->all();
    }
}
