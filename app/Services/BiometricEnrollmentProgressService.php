<?php

namespace App\Services;

use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\EmployeeBiometric;

class BiometricEnrollmentProgressService
{
    public function forCommand(Employee $employee, DeviceCommand $command): array
    {
        $command->loadMissing('device');

        $biometricType = $this->resolveBiometricType($command);
        $templateIndex = $this->resolveTemplateIndex($command, $biometricType);
        $recentTemplate = $this->resolveRecentTemplate($employee, $command, $biometricType, $templateIndex);
        $phase = $this->resolvePhase($command, $recentTemplate);
        $steps = $this->buildSteps($command, $recentTemplate, $biometricType);

        return [
            'command_id' => $command->id,
            'command_status' => $command->status,
            'phase' => $phase,
            'message' => $this->phaseMessage($phase, $command, $biometricType),
            'progress' => $this->progressPercentage($steps),
            'biometric_type' => $biometricType,
            'template_index' => $templateIndex,
            'employee' => [
                'id' => $employee->id,
                'employee_id' => $employee->employee_id,
                'name' => $employee->full_name,
            ],
            'device' => [
                'id' => $command->device?->id,
                'name' => $command->device?->name ?? 'Unknown device',
                'serial_number' => $command->device?->serial_number,
                'online' => $command->device?->isOnline() ?? false,
            ],
            'retry' => (int) ($command->parameters['retry'] ?? 3),
            'steps' => $steps,
            'template_received' => $recentTemplate !== null,
            'template' => $recentTemplate ? [
                'id' => $recentTemplate->id,
                'device_id' => $recentTemplate->device_id,
                'device_name' => $recentTemplate->device?->name,
                'template_index' => $recentTemplate->template_index,
                'data_size' => $recentTemplate->data_size,
                'updated_at' => $recentTemplate->updated_at?->toIso8601String(),
            ] : null,
            'sent_at' => $command->sent_at?->toIso8601String(),
            'acknowledged_at' => $command->acknowledged_at?->toIso8601String(),
            'error_message' => $command->error_message,
        ];
    }

    private function resolveBiometricType(DeviceCommand $command): string
    {
        $type = strtolower(trim((string) ($command->parameters['biometric_type'] ?? '')));

        if (in_array($type, [EmployeeBiometric::TYPE_FINGERPRINT, EmployeeBiometric::TYPE_FACE], true)) {
            return $type;
        }

        return match ($command->command) {
            'ENROLL_FACE' => EmployeeBiometric::TYPE_FACE,
            default => EmployeeBiometric::TYPE_FINGERPRINT,
        };
    }

    private function resolveTemplateIndex(DeviceCommand $command, string $biometricType): ?string
    {
        $candidate = $command->parameters['template_index']
            ?? $command->parameters['fid']
            ?? ($biometricType === EmployeeBiometric::TYPE_FACE ? '50' : '0');

        $candidate = trim((string) $candidate);

        return $candidate !== '' ? $candidate : null;
    }

    private function resolveRecentTemplate(
        Employee $employee,
        DeviceCommand $command,
        string $biometricType,
        ?string $templateIndex,
    ): ?EmployeeBiometric {
        return EmployeeBiometric::query()
            ->with('device')
            ->where('employee_id', $employee->id)
            ->where('device_id', $command->device_id)
            ->where('biometric_type', $biometricType)
            ->when($templateIndex !== null, function ($query) use ($templateIndex): void {
                $query->where('template_index', $templateIndex);
            })
            ->where('updated_at', '>=', $command->created_at)
            ->latest('updated_at')
            ->first();
    }

    private function resolvePhase(DeviceCommand $command, ?EmployeeBiometric $template): string
    {
        if ($template) {
            return 'completed';
        }

        if (
            $command->status === DeviceCommand::STATUS_FAILED
            || $command->failed_at !== null
            || $command->error_message
        ) {
            return 'failed';
        }

        if ($command->acknowledged_at) {
            return 'uploading';
        }

        if ($command->sent_at) {
            return 'capturing';
        }

        return 'queued';
    }

    private function buildSteps(DeviceCommand $command, ?EmployeeBiometric $template, string $biometricType): array
    {
        $failed = $command->status === DeviceCommand::STATUS_FAILED
            || $command->failed_at !== null
            || (bool) $command->error_message;
        $devicePickedUp = $command->sent_at !== null || $command->acknowledged_at !== null || $template !== null;
        $captureAccepted = $command->acknowledged_at !== null || $template !== null;
        $stored = $template !== null;
        $captureLabel = $biometricType === EmployeeBiometric::TYPE_FACE ? 'Capture Face' : 'Capture Finger';
        $captureDescription = $biometricType === EmployeeBiometric::TYPE_FACE
            ? 'The employee should look at the camera until the terminal confirms the face capture.'
            : 'The employee should place the same finger on the scanner repeatedly until the device accepts it.';
        $storedDescription = $biometricType === EmployeeBiometric::TYPE_FACE
            ? 'The face template is now saved in the local biometric store.'
            : 'The fingerprint template is now saved in the local biometric store.';

        return [
            [
                'key' => 'queued',
                'label' => 'Command Queued',
                'description' => 'The ADMS server stored the remote enrollment command.',
                'state' => 'completed',
            ],
            [
                'key' => 'device',
                'label' => 'Device Picked It Up',
                'description' => 'The terminal polled ADMS and received the enrollment command.',
                'state' => $devicePickedUp ? 'completed' : 'active',
            ],
            [
                'key' => 'capture',
                'label' => $captureLabel,
                'description' => $captureDescription,
                'state' => $failed ? 'error' : ($captureAccepted ? 'completed' : ($devicePickedUp ? 'active' : 'pending')),
            ],
            [
                'key' => 'upload',
                'label' => 'Upload To ADMS',
                'description' => 'The device sends the captured template back to the server through /iclock/cdata.',
                'state' => $failed ? 'error' : ($stored ? 'completed' : ($captureAccepted ? 'active' : 'pending')),
            ],
            [
                'key' => 'stored',
                'label' => 'Enrollment Stored',
                'description' => $storedDescription,
                'state' => $failed ? 'error' : ($stored ? 'completed' : 'pending'),
            ],
        ];
    }

    private function progressPercentage(array $steps): int
    {
        $total = count($steps);
        if ($total === 0) {
            return 0;
        }

        $completed = collect($steps)
            ->filter(static fn (array $step): bool => $step['state'] === 'completed')
            ->count();

        return (int) floor(($completed / $total) * 100);
    }

    private function phaseMessage(string $phase, DeviceCommand $command, string $biometricType): string
    {
        return match ($phase) {
            'completed' => ucfirst($biometricType).' template received by ADMS. The enrollment is complete.',
            'uploading' => 'The device accepted the capture. Waiting for the template upload to arrive in ADMS.',
            'capturing' => $biometricType === EmployeeBiometric::TYPE_FINGERPRINT
                ? 'Command delivered. Ask the employee to place the same finger on the scanner 3 times.'
                : 'Command delivered. Ask the employee to look at the camera until the device confirms capture.',
            'failed' => $command->error_message ?: 'The device rejected the enrollment command.',
            default => 'Waiting for the target device to poll ADMS and start enrollment.',
        };
    }
}
