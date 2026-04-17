<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_is_accessible_without_authentication(): void
    {
        $device = Device::query()->create([
            'name' => 'Main Gate',
            'sn' => 'ACME001',
            'serial_number' => 'ACME001',
            'status' => 'active',
            'last_seen' => now(),
            'last_heartbeat' => now(),
        ]);

        $response = $this->get('/admin/dashboard');

        $response->assertOk();
        $response->assertSee('Open Access', false);
        $response->assertSee(route('admin.devices.command', $device), false);
    }

    public function test_admin_dashboard_renders_without_missing_route_errors(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin User',
            'email' => 'admin-dashboard@example.com',
            'role' => 'admin',
            'email_verified_at' => now(),
            'password' => 'password',
        ]);

        $device = Device::query()->create([
            'name' => 'Main Gate',
            'sn' => 'ACME001',
            'serial_number' => 'ACME001',
            'status' => 'active',
            'last_seen' => now(),
            'last_heartbeat' => now(),
        ]);

        AttendanceLog::query()->create([
            'device_id' => $device->id,
            'employee_code' => '1001',
            'log_type' => 'checkin',
            'verify_type' => 'unknown',
            'log_time' => now(),
            'status' => 'processed',
        ]);

        $response = $this->actingAs($admin)->get('/admin/dashboard');

        $response->assertOk();
        $response->assertSee(route('admin.devices.command', $device), false);
        $response->assertSee(route('admin.logs.index'), false);
    }

    public function test_admin_can_queue_a_device_command_from_the_dashboard(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin User',
            'email' => 'admin-queue@example.com',
            'role' => 'admin',
            'email_verified_at' => now(),
            'password' => 'password',
        ]);

        $device = Device::query()->create([
            'name' => 'Main Gate',
            'sn' => 'ACME001',
            'serial_number' => 'ACME001',
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin)
            ->from('/admin/dashboard')
            ->post(route('admin.devices.command', $device), [
                'command' => 'REBOOT',
            ]);

        $response->assertRedirect('/admin/dashboard');

        $this->assertDatabaseHas('device_commands', [
            'device_id' => $device->id,
            'command' => 'RESTART',
            'status' => 'pending',
            'created_by' => $admin->id,
        ]);
    }

    public function test_guest_can_queue_a_device_command_from_the_dashboard(): void
    {
        $device = Device::query()->create([
            'name' => 'Main Gate',
            'sn' => 'ACME001',
            'serial_number' => 'ACME001',
            'status' => 'active',
        ]);

        $response = $this->from('/admin/dashboard')
            ->post(route('admin.devices.command', $device), [
                'command' => 'REBOOT',
            ]);

        $response->assertRedirect('/admin/dashboard');

        $this->assertDatabaseHas('device_commands', [
            'device_id' => $device->id,
            'command' => 'RESTART',
            'status' => 'pending',
            'created_by' => null,
        ]);
    }
}
