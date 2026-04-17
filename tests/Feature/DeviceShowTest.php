<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\RawLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_details_page_shows_raw_requests_and_synced_employees_without_attendance(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin User',
            'email' => 'device-show@example.com',
            'role' => 'admin',
            'email_verified_at' => now(),
            'password' => 'password',
        ]);

        $device = Device::query()->create([
            'name' => 'ZKTeco NYU7254801353',
            'sn' => 'NYU7254801353',
            'serial_number' => 'NYU7254801353',
            'status' => 'active',
            'last_seen' => now(),
            'last_heartbeat' => now(),
        ]);

        RawLog::query()->create([
            'device_id' => $device->id,
            'endpoint' => '/iclock/cdata',
            'payload' => "PIN=9001\tName=John Doe\tCard=1234\tUID=9001",
            'parsed_data' => ['type' => 'userinfo', 'table' => 'USERINFO'],
            'processing_status' => 'processed',
            'record_count' => 1,
        ]);

        Employee::query()->create([
            'employee_id' => '9001',
            'device_id' => $device->id,
            'uid' => '9001',
            'card_no' => '1234',
            'name' => 'John Doe',
            'status' => 'active',
            'raw_payload' => ['PIN' => '9001', 'Name' => 'John Doe', 'Card' => '1234', 'UID' => '9001'],
        ]);

        $response = $this->actingAs($admin)->get(route('admin.devices.show', $device));

        $response->assertOk();
        $response->assertSee('No attendance logs yet', false);
        $response->assertSee('/iclock/cdata', false);
        $response->assertSee('John Doe', false);
        $response->assertSee('Synced Employees', false);
        $response->assertSee('Recent ADMS Requests', false);
    }

    public function test_admin_can_queue_pull_employees_from_device_details_page(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin User',
            'email' => 'device-pull@example.com',
            'role' => 'admin',
            'email_verified_at' => now(),
            'password' => 'password',
        ]);

        $device = Device::query()->create([
            'name' => 'ZKTeco NYU7254801353',
            'sn' => 'NYU7254801353',
            'serial_number' => 'NYU7254801353',
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin)
            ->from(route('admin.devices.show', $device))
            ->post(route('admin.devices.pull', $device), [
                'dataset' => 'employees',
            ]);

        $response->assertRedirect(route('admin.devices.show', $device));

        $this->assertDatabaseHas('device_commands', [
            'device_id' => $device->id,
            'command' => 'PULL_EMPLOYEES',
            'status' => DeviceCommand::STATUS_PENDING,
            'created_by' => $admin->id,
        ]);
    }
}
