<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\RawLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLogsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_logs_page_renders_when_related_devices_are_soft_deleted(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin User',
            'email' => 'admin-logs@example.com',
            'role' => 'admin',
            'email_verified_at' => now(),
            'password' => 'password',
        ]);

        $device = Device::query()->create([
            'name' => 'Deleted Gate',
            'sn' => 'DEL001',
            'serial_number' => 'DEL001',
            'status' => 'active',
        ]);

        RawLog::query()->create([
            'device_id' => $device->id,
            'endpoint' => '/iclock/cdata',
            'payload' => 'PIN=1001',
            'parsed_data' => ['SN' => 'DEL001'],
            'processing_status' => 'processed',
            'record_count' => 1,
        ]);

        DeviceCommand::query()->create([
            'device_id' => $device->id,
            'command' => 'RESTART',
            'status' => DeviceCommand::STATUS_PENDING,
        ]);

        $device->delete();

        $response = $this->actingAs($admin)->get(route('admin.logs.index'));

        $response->assertOk();
        $response->assertSee('Deleted Gate', false);
        $response->assertDontSee('Attempt to read property', false);
    }
}
