<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAttendancePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_attendance_page_renders_when_related_employee_is_soft_deleted(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin User',
            'email' => 'admin-attendance@example.com',
            'role' => 'admin',
            'email_verified_at' => now(),
            'password' => 'password',
        ]);

        $device = Device::query()->create([
            'name' => 'Main Gate',
            'sn' => 'ATT001',
            'serial_number' => 'ATT001',
            'status' => 'active',
        ]);

        $employee = Employee::query()->create([
            'employee_id' => '1001',
            'uid' => '1001',
            'name' => 'John Doe',
            'status' => 'active',
        ]);

        AttendanceLog::query()->create([
            'device_id' => $device->id,
            'employee_id' => $employee->id,
            'employee_code' => '1001',
            'log_type' => 'checkin',
            'verify_type' => 'unknown',
            'log_time' => now(),
            'status' => 'processed',
        ]);

        $employee->delete();

        $response = $this->actingAs($admin)->get(route('admin.attendance.index'));

        $response->assertOk();
        $response->assertSee('John Doe (deleted)', false);
        $response->assertDontSee('Missing required parameter', false);
    }
}
