<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\Employee;
use App\Services\BiometricSyncService;
use Illuminate\Console\Command;

class SyncEmployeeBiometricTemplatesCommand extends Command
{
    protected $signature = 'biometric:sync-employee-templates
        {employee : Employee code or local employee id}
        {--source-device= : Optional source device IP address}
        {--no-udp : Skip live UDP template fetch and use the locally stored biometric copies only}
        {--no-overwrite : Do not overwrite matching templates on target devices}';

    protected $description = 'Manually queue biometric template sync for one employee across all registered devices.';

    public function handle(BiometricSyncService $biometricSyncService): int
    {
        $employee = $this->resolveEmployee((string) $this->argument('employee'));

        if (! $employee) {
            $this->error('Employee not found.');

            return self::FAILURE;
        }

        $sourceDevice = null;
        if (filled($this->option('source-device'))) {
            $sourceDevice = Device::query()->where('ip_address', trim((string) $this->option('source-device')))->first();

            if (! $sourceDevice) {
                $this->error('Source device not found.');

                return self::FAILURE;
            }
        }

        $summary = $biometricSyncService->syncEmployeeTemplates($employee, [
            'source_device' => $sourceDevice,
            'prefer_udp' => ! (bool) $this->option('no-udp'),
            'overwrite' => ! (bool) $this->option('no-overwrite'),
        ]);

        $this->info('Biometric sync queued.');
        $this->line("Employee: {$summary['employee_id']}");
        $this->line("Source device: {$summary['source_device']}");
        $this->line("Templates persisted: {$summary['template_count']}");
        $this->line("Jobs dispatched: {$summary['jobs_dispatched']}");
        $this->line("Skipped targets: {$summary['skipped']}");

        foreach ($summary['warnings'] as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }

    private function resolveEmployee(string $value): ?Employee
    {
        return Employee::query()
            ->where('employee_id', trim($value))
            ->orWhere('id', ctype_digit($value) ? (int) $value : 0)
            ->first();
    }
}
