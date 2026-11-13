<?php

namespace App\Services;

use App\Models\DeviceCommand;

class ZKTecoCommandFormatter
{
    public function __construct(
        protected SystemClockService $systemClockService,
    ) {}

    public function format(DeviceCommand $command): string
    {
        $payload = trim((string) ($command->command_payload ?? ''));
        if ($payload !== '') {
            return str_starts_with($payload, 'C:') ? $payload : "C:{$command->id}:{$payload}";
        }

        return match ($command->command) {
            'RESTART', 'REBOOT' => "C:{$command->id}:REBOOT",
            'CLEAR_LOGS' => "C:{$command->id}:CLEAR LOG",
            'SET_TIME', 'UPDATE_TIME' => $this->formatTimeCommand($command),
            'SET_TIMEZONE' => $this->formatOptionCommand($command),
            'ENABLE_ATTENDANCE' => "C:{$command->id}:SET OPTION Realtime=1",
            'DISABLE_ATTENDANCE' => "C:{$command->id}:SET OPTION Realtime=0",
            'SET_ATTENDANCE_MODE' => $this->formatOptionCommand($command),
            'SYNC_EMPLOYEES' => $this->formatUserCommand($command),
            'SYNC_BIOMETRICS' => $this->formatBiometricCommand($command),
            'ENROLL_FINGERPRINT' => $this->formatFingerprintEnrollmentCommand($command),
            'ENROLL_FACE' => $this->formatFaceEnrollmentCommand($command),
            'PULL_EMPLOYEES' => $this->formatEmployeePullCommand($command),
            'PULL_ATTENDANCE' => $this->formatAttendancePullCommand($command),
            'RM_ADMIN_PRIVILEGE' => "C:{$command->id}:ClearAdmin",
            default => $this->formatRawCommand($command),
        };
    }

    private function formatTimeCommand(DeviceCommand $command): string
    {
        $parameters = $command->parameters ?? [];

        if (! empty($parameters['option']) && array_key_exists('value', $parameters)) {
            return "C:{$command->id}:SET OPTIONS {$parameters['option']}={$parameters['value']}";
        }

        // PUSH SDK Appendix 5: DateTime is OldEncodeTime (a decimal
        // number), not human-readable YYYY-MM-DD HH:MM:SS or Unix epoch.
        // Always calculate at dispatch to avoid stale clock commands.
        return "C:{$command->id}:SET OPTIONS DateTime="
            .$this->systemClockService->encodeForPushCommand();
    }

    private function formatOptionCommand(DeviceCommand $command): string
    {
        $parameters = $command->parameters ?? [];
        $option = $this->sanitize((string) ($parameters['option'] ?? 'Realtime'));
        $value = $this->sanitize((string) ($parameters['value'] ?? '1'));

        return "C:{$command->id}:SET OPTIONS {$option}={$value}";
    }

    private function formatUserCommand(DeviceCommand $command): string
    {
        $parameters = $command->parameters ?? [];
        $action = strtolower((string) ($parameters['action'] ?? 'add_user'));

        if ($action === 'query') {
            $pin = $this->sanitize((string) ($parameters['pin'] ?? $parameters['employee_code'] ?? ''));

            return "C:{$command->id}:DATA QUERY USERINFO PIN={$pin}";
        }

        $pin = $this->sanitize((string) ($parameters['pin'] ?? $parameters['employee_code'] ?? ''));
        $name = $this->sanitize((string) ($parameters['name'] ?? 'Unknown User'));
        $card = $this->sanitize((string) ($parameters['card_no'] ?? ''));
        $privilege = (int) ($parameters['privilege'] ?? 0);
        $password = $this->sanitize((string) ($parameters['password'] ?? ''));
        $fields = [
            "PIN={$pin}",
            "Name={$name}",
            'Pri='.max(0, min($privilege, 14)),
            "Passwd={$password}",
            "Card={$card}",
        ];

        $optionalFields = [
            'Grp' => $parameters['group_code'] ?? null,
            'TZ' => $parameters['timezone_code'] ?? null,
            'Verify' => $parameters['verification_mode'] ?? null,
            'ViceCard' => $parameters['vice_card_no'] ?? null,
            'StartDatetime' => $parameters['valid_from'] ?? null,
            'EndDatetime' => $parameters['valid_until'] ?? null,
        ];

        foreach ($optionalFields as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $fields[] = "{$label}=".$this->sanitize((string) $value);
        }

        return "C:{$command->id}:DATA UPDATE USERINFO ".implode("\t", $fields);
    }

    private function formatEmployeePullCommand(DeviceCommand $command): string
    {
        $parameters = $command->parameters ?? [];
        $pin = $this->sanitize((string) ($parameters['pin'] ?? $parameters['employee_code'] ?? ''));

        if ($pin !== '') {
            return "C:{$command->id}:DATA QUERY USERINFO PIN={$pin}";
        }

        return "C:{$command->id}:DATA QUERY USERINFO";
    }

    private function formatAttendancePullCommand(DeviceCommand $command): string
    {
        $parameters = $command->parameters ?? [];
        $parts = ["C:{$command->id}:DATA QUERY ATTLOG"];

        $startTime = $this->sanitize((string) ($parameters['start_time'] ?? ''));
        $endTime = $this->sanitize((string) ($parameters['end_time'] ?? ''));

        if ($startTime !== '') {
            $parts[] = "StartTime={$startTime}";
        }

        if ($endTime !== '') {
            $parts[] = "EndTime={$endTime}";
        }

        return implode(' ', $parts);
    }

    private function formatBiometricCommand(DeviceCommand $command): string
    {
        $parameters = $command->parameters ?? [];
        $pin = $this->sanitize((string) ($parameters['pin'] ?? $parameters['employee_code'] ?? ''));
        $biometricType = strtolower(trim((string) ($parameters['biometric_type'] ?? '')));

        if ($pin === '') {
            throw new \InvalidArgumentException('Biometric sync command requires a device PIN');
        }

        return match ($biometricType) {
            'fingerprint' => $this->formatFingerprintCommand($command, $pin, $parameters),
            'face' => $this->formatFaceCommand($command, $pin, $parameters),
            'photo' => $this->formatPhotoCommand($command, $pin, $parameters),
            default => throw new \InvalidArgumentException('Unsupported biometric sync command type'),
        };
    }

    private function formatFingerprintCommand(DeviceCommand $command, string $pin, array $parameters): string
    {
        $transfer = $this->prepareBinaryTransfer($parameters, 'fingerprint');
        $fields = [
            "PIN={$pin}",
            'FID='.$this->sanitize((string) ($parameters['template_index'] ?? '0')),
            'Size='.$transfer['size'],
        ];

        if (array_key_exists('is_valid', $parameters) && $parameters['is_valid'] !== null && $parameters['is_valid'] !== '') {
            $fields[] = 'Valid='.($parameters['is_valid'] ? '1' : '0');
        }

        $fields[] = "TMP={$transfer['data']}";

        return "C:{$command->id}:DATA UPDATE FINGERTMP ".implode("\t", $fields);
    }

    private function formatFaceCommand(DeviceCommand $command, string $pin, array $parameters): string
    {
        $transfer = $this->prepareBinaryTransfer($parameters, 'face');
        $fields = [
            "PIN={$pin}",
            'FID='.$this->sanitize((string) ($parameters['template_index'] ?? '50')),
            'Size='.$transfer['size'],
        ];

        if (array_key_exists('is_valid', $parameters) && $parameters['is_valid'] !== null && $parameters['is_valid'] !== '') {
            $fields[] = 'Valid='.($parameters['is_valid'] ? '1' : '0');
        }

        $fields[] = "TMP={$transfer['data']}";

        return "C:{$command->id}:DATA UPDATE FACE ".implode("\t", $fields);
    }

    private function formatPhotoCommand(DeviceCommand $command, string $pin, array $parameters): string
    {
        $fileName = $this->sanitize((string) ($parameters['file_name'] ?? "{$pin}.jpg"));
        $transfer = $this->prepareBinaryTransfer($parameters, 'photo');

        $fields = [
            "PIN={$pin}",
            "FileName={$fileName}",
            'Size='.$transfer['size'],
        ];

        $fields[] = "Content={$transfer['data']}";

        return "C:{$command->id}:DATA UPDATE USERPIC ".implode("\t", $fields);
    }

    private function formatFingerprintEnrollmentCommand(DeviceCommand $command): string
    {
        $parameters = $command->parameters ?? [];
        $pin = $this->sanitize((string) ($parameters['pin'] ?? $parameters['employee_code'] ?? ''));

        if ($pin === '') {
            throw new \InvalidArgumentException('Fingerprint enrollment command requires a device PIN');
        }

        $fingerIndex = max(0, min((int) ($parameters['fid'] ?? $parameters['template_index'] ?? 0), 9));
        $retry = max(1, min((int) ($parameters['retry'] ?? 3), 15));
        $overwrite = $this->normalizeBooleanParameter($parameters['overwrite'] ?? true) ? '1' : '0';

        return "C:{$command->id}:ENROLL_FP PIN={$pin}\tFID={$fingerIndex}\tRETRY={$retry}\tOVERWRITE={$overwrite}";
    }

    private function formatFaceEnrollmentCommand(DeviceCommand $command): string
    {
        $parameters = $command->parameters ?? [];
        $pin = $this->sanitize((string) ($parameters['pin'] ?? $parameters['employee_code'] ?? ''));

        if ($pin === '') {
            throw new \InvalidArgumentException('Face enrollment command requires a device PIN');
        }

        $retry = max(1, min((int) ($parameters['retry'] ?? 3), 15));
        $overwrite = $this->normalizeBooleanParameter($parameters['overwrite'] ?? true) ? '1' : '0';
        $faceType = max(0, min((int) ($parameters['type'] ?? config('zkteco.face_enrollment_type', 2)), 9));

        $fields = [
            "TYPE={$faceType}",
            "PIN={$pin}",
        ];

        $cardNumber = $this->sanitize((string) ($parameters['card_no'] ?? ''));
        if ($cardNumber !== '') {
            $fields[] = "CardNo={$cardNumber}";
        }

        $fields[] = "RETRY={$retry}";
        $fields[] = "OVERWRITE={$overwrite}";

        return "C:{$command->id}:ENROLL_BIO ".implode("\t", $fields);
    }

    private function formatRawCommand(DeviceCommand $command): string
    {
        $commandText = trim((string) $command->command);

        return str_starts_with($commandText, 'C:') ? $commandText : "C:{$command->id}:{$commandText}";
    }

    private function sanitize(string $value): string
    {
        return str_replace(["\r", "\n", "\t"], ' ', trim($value));
    }

    private function prepareBinaryTransfer(array $parameters, string $type): array
    {
        $rawTemplate = trim((string) ($parameters['template_data'] ?? ''));
        if ($rawTemplate === '') {
            throw new \InvalidArgumentException(ucfirst($type).' sync command requires template data');
        }

        $transport = strtolower(trim((string) ($parameters['template_transport'] ?? '')));
        $faceUploadPrefixed = $type === 'face'
            && $this->normalizeBooleanParameter($parameters['face_upload_prefixed'] ?? false);

        $normalizedTemplate = preg_replace('/\s+/', '', trim($rawTemplate)) ?? trim($rawTemplate);
        $decoded = base64_decode($normalizedTemplate, true);

        if ($faceUploadPrefixed && $decoded !== false && strlen($decoded) > 16) {
            $encoded = base64_encode(substr($decoded, 16));

            return [
                'data' => $encoded,
                'size' => strlen($encoded),
            ];
        }

        if ($transport === 'udp') {
            $encoded = base64_encode($decoded !== false ? $decoded : $rawTemplate);

            return [
                'data' => $encoded,
                'size' => strlen($encoded),
            ];
        }

        return [
            'data' => $rawTemplate,
            'size' => $this->resolveTransferSize($parameters, $rawTemplate),
        ];
    }

    private function resolveTransferSize(array $parameters, string $payload): int
    {
        if (array_key_exists('data_size', $parameters) && $parameters['data_size'] !== null && $parameters['data_size'] !== '') {
            return max(0, (int) $parameters['data_size']);
        }

        return strlen($payload);
    }

    private function normalizeBooleanParameter(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $value = strtolower(trim((string) $value));

        return ! in_array($value, ['0', 'false', 'off', 'no'], true);
    }
}
