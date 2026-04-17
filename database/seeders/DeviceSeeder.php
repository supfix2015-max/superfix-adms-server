<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Device;
use Illuminate\Support\Str;

class DeviceSeeder extends Seeder
{
    public function run(): void
    {
        $devices = [
            [
'name' => 'Main Entrance Device',
'sn' => 'SK172018010001',
                'ip_address' => '192.168.1.100',
                'port' => 8080,
                'status' => 'active',
                'device_type' => 'face',
                'firmware_version' => 'V2.1.12',
                'hardware_version' => 'V3.0',
                'capacity' => 50,
                'api_token' => Str::random(32),
                'last_heartbeat' => now(),
                'last_sync' => now(),
            ],
            [
                'name' => 'Office Block Device',
                'sn' => 'SK172018010002',
                'ip_address' => '192.168.1.101',
                'port' => 8080,
                'status' => 'active',
                'device_type' => 'mixed',
                'firmware_version' => 'V2.1.12',
                'hardware_version' => 'V3.0',
                'capacity' => 75,
                'api_token' => Str::random(32),
                'last_heartbeat' => now(),
                'last_sync' => now(),
            ],
            [
                'name' => 'Parking Area Device',
                'sn' => 'SK172018010003',
                'ip_address' => '192.168.1.102',
                'port' => 8080,
                'status' => 'inactive',
                'device_type' => 'rfid',
                'firmware_version' => 'V2.1.10',
                'hardware_version' => 'V2.5',
                'capacity' => 30,
                'api_token' => Str::random(32),
            ],
        ];

        foreach ($devices as $device) {
            Device::create($device);
        }
    }
}
