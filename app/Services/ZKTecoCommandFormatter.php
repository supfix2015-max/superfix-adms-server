<?php

namespace App\Services;

use App\Models\DeviceCommand;

class ZKTecoCommandFormatter
{
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
            'ENABLE_ATTENDANCE' => "C:{$command->id}:SET OPTION Realtime=1",
            'DISABLE_ATTENDANCE' => "C:{$command->id}:SET OPTION Realtime=0",
            'SET_ATTENDANCE_MODE' => $this->formatOptionCommand($command),
            'SYNC_EMPLOYEES' => $this->formatUserCommand($command),
            'PULL_EMPLOYEES' => $this->formatEmployeePullCommand($command),
            'PULL_ATTENDANCE' => $this->formatAttendancePullCommand($command),
            default => $this->formatRawCommand($command),
        };
    }

    private function formatTimeCommand(DeviceCommand $command): string
    {
        $parameters = $command->parameters ?? [];

        if (! empty($parameters['option']) && array_key_exists('value', $parameters)) {
            return "C:{$command->id}:SET OPTION {$parameters['option']}={$parameters['value']}";
        }

        $timestamp = $parameters['timestamp'] ?? now()->format('Y-m-d H:i:s');

        return "C:{$command->id}:SET OPTION DateTime={$timestamp}";
    }

    private function formatOptionCommand(DeviceCommand $command): string
    {
        $parameters = $command->parameters ?? [];
        $option = $this->sanitize((string) ($parameters['option'] ?? 'Realtime'));
        $value = $this->sanitize((string) ($parameters['value'] ?? '1'));

        return "C:{$command->id}:SET OPTION {$option}={$value}";
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

        return "C:{$command->id}:DATA UPDATE USERINFO PIN={$pin}\tName={$name}\tPri={$privilege}\tCard={$card}";
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

    private function formatRawCommand(DeviceCommand $command): string
    {
        $commandText = trim((string) $command->command);

        return str_starts_with($commandText, 'C:') ? $commandText : "C:{$command->id}:{$commandText}";
    }

    private function sanitize(string $value): string
    {
        return str_replace(["\r", "\n", "\t"], ' ', trim($value));
    }
}
