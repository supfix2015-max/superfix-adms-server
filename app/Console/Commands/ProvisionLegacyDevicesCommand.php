<?php

namespace App\Console\Commands;

use App\Models\Device;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Controlled onboarding of original dbo.Device registrations to operational
 * adms.devices. Never copies users/biometrics/punches or changes dbo records.
 */
class ProvisionLegacyDevicesCommand extends Command
{
    protected $signature = 'adms:provision-legacy-devices {--dry-run : Preview registrations without writing}';

    protected $description = 'Register known original ZKTeco terminals in adms.devices only (office UA300 retained)';

    public function handle(): int
    {
        $db = DB::connection('sqlsrv');
        $identity = $db->selectOne('SELECT DB_NAME() AS db, SCHEMA_NAME() AS schema_name');

        if (strcasecmp((string) ($identity->db ?? ''), 'Att2000_V4') !== 0
            || strcasecmp((string) ($identity->schema_name ?? ''), 'adms') !== 0) {
            $this->error('Refusing: default application connection must be Att2000_V4.adms.');
            return self::FAILURE;
        }

        if (config('adms_pilot.enabled') || config('zkteco.dispatch_commands')
            || config('legacy_attendance.attendance_write_enabled')) {
            $this->error('Keep ADMS pilot, dispatch and historical attendance writes disabled during onboarding.');
            return self::FAILURE;
        }

        $historic = DB::connection('legacy_sqlsrv')->table('dbo.Device')
            ->select(['DeviceSerialNumber', 'DeviceAliasName', 'DeviceName'])
            ->orderBy('Id')->get();

        $seen = [];
        $created = 0;
        $existing = 0;
        $skipped = 0;

        foreach ($historic as $original) {
            $serial = trim((string) ($original->DeviceSerialNumber ?? ''));
            if (! preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $serial) || isset($seen[$serial])) {
                $skipped++;
                continue;
            }
            $seen[$serial] = true;

            $record = Device::withTrashed()->where(function ($query) use ($serial): void {
                $query->where('serial_number', $serial)->orWhere('sn', $serial);
            })->first();

            if ($record) {
                $existing++;
                if ($record->trashed()) {
                    $this->warn("Skipped soft-deleted serial {$serial}; explicit restoration required.");
                    $skipped++;
                }
                continue;
            }

            if ($this->option('dry-run')) {
                $created++;
                continue;
            }

            Device::query()->create([
                'name' => trim((string) ($original->DeviceAliasName ?: $original->DeviceName))
                    ?: "Original terminal {$serial}",
                'sn' => $serial,
                'serial_number' => $serial,
                'status' => Device::STATUS_INACTIVE,
                'device_type' => 'ZKTeco ADMS',
                'port' => (int) config('zkteco.default_device_port', 8000),
                'api_token' => Str::random(48),
                'notes' => 'Registered from original dbo.Device. Historical status not proof of live connection.',
            ]);
            $created++;
        }

        $office = Device::withTrashed()->where(function ($q): void {
            $q->where('serial_number', 'BZD5261200843')->orWhere('sn', 'BZD5261200843');
        })->first();

        $this->table(['Operation', 'Count'], [
            [$this->option('dry-run') ? 'Would create' : 'Created in adms.devices', $created],
            ['Existing unchanged', $existing],
            ['Skipped malformed/duplicate/soft-deleted rows', $skipped],
            ['Office UA300 registered', $office && ! $office->trashed() ? 'YES' : 'NO'],
        ]);
        $this->warn('No historical dbo records were written. Registration does not establish remote identity, physical connection or command authorization.');
        return self::SUCCESS;
    }
}
