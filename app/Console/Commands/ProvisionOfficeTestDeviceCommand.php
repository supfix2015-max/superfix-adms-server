<?php

namespace App\Console\Commands;

use App\Models\Device;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Explicit, one-time provisioning of the office test UA300 into adms.devices.
 * This does NOT synchronize any employees, write original dbo.Device, or
 * authorize ADMS requests from the physical terminal.
 */
class ProvisionOfficeTestDeviceCommand extends Command
{
    protected $signature = 'adms:provision-office-test-device';

    protected $description = 'Register office UA300 BZD5261200843 in isolated adms.devices (no device communication)';

    public function handle(): int
    {
        if (config('database.default') !== 'sqlsrv'
            || strcasecmp((string) config('database.connections.sqlsrv.schema'), 'adms') !== 0) {
            $this->error('Unsafe operational connection; expected SQL Server adms schema.');
            return self::FAILURE;
        }

        $connection = DB::connection('sqlsrv');
        $identity = $connection->selectOne('SELECT DB_NAME() AS db, SCHEMA_NAME() AS schema_name');
        if (strcasecmp((string) ($identity->db ?? ''), 'Att2000_V4') !== 0
            || strcasecmp((string) ($identity->schema_name ?? ''), 'adms') !== 0) {
            $this->error('Database/schema identity mismatch. No device added.');
            return self::FAILURE;
        }

        if (config('adms_pilot.enabled', false)
            || config('zkteco.dispatch_commands', false)
            || config('legacy_attendance.attendance_write_enabled', false)) {
            $this->error('Disable ADMS pilot, device commands, and original attendance writes before provisioning.');
            return self::FAILURE;
        }

        $serial = 'BZD5261200843';
        $existing = Device::withTrashed()
            ->where('serial_number', $serial)
            ->orWhere('sn', $serial)
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                $this->error('Office UA300 record is soft-deleted; not restoring it implicitly.');
                return self::FAILURE;
            }
            $this->info('Office test UA300 is already present in adms.devices; no change made.');
            $this->line('Device ID: '.$existing->id.' | Serial: '.$serial);
            return self::SUCCESS;
        }

        $device = Device::create([
            'name' => 'Office Test UA300',
            'sn' => $serial,
            'serial_number' => $serial,
            'device_type' => 'UA300',
            'status' => Device::STATUS_INACTIVE,
            'port' => 8000,
            'api_token' => Str::random(48),
            'notes' => 'Office laboratory device. ADMS pilot and outbound commands must remain disabled until tested.',
        ]);

        $this->info('Office test UA300 registered in adms.devices.');
        $this->table(['Property', 'Value'], [
            ['Device ID', (string) $device->id],
            ['Serial', $serial],
            ['Initial status', 'Inactive / no verified inbound contact'],
            ['API token', 'Generated and saved privately (not printed)'],
            ['Historical dbo.Device', 'Unchanged'],
            ['ADMS pilot', 'OFF'],
        ]);
        $this->warn('Registration alone does not mean the device has connected or been granted access.');
        return self::SUCCESS;
    }
}
