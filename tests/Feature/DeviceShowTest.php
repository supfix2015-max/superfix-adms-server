<?php

namespace Tests\Feature;

use App\Models\BiometricTemplate;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\EmployeeBiometric;
use App\Models\ErrorLog;
use App\Models\RawLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            'name' => 'ZKTeco TEST-SHOW-001',
            'sn' => 'TEST-SHOW-001',
            'serial_number' => 'TEST-SHOW-001',
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
            'name' => 'ZKTeco TEST-SHOW-002',
            'sn' => 'TEST-SHOW-002',
            'serial_number' => 'TEST-SHOW-002',
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

    public function test_device_delete_from_admin_route_force_removes_device_and_cleans_related_references(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin User',
            'email' => 'device-delete@example.com',
            'role' => 'admin',
            'email_verified_at' => now(),
            'password' => 'password',
        ]);

        $device = Device::query()->create([
            'name' => 'Delete Gate',
            'sn' => 'DELETE-DEVICE-001',
            'serial_number' => 'DELETE-DEVICE-001',
            'status' => 'active',
        ]);

        $employee = Employee::query()->create([
            'employee_id' => '920001',
            'device_id' => $device->id,
            'name' => 'Device Linked Employee',
            'status' => 'active',
        ]);

        DB::table('attendance_logs')->insert([
            'device_id' => $device->id,
            'employee_id' => $employee->id,
            'employee_code' => $employee->employee_id,
            'log_type' => 'checkin',
            'verify_type' => 'unknown',
            'log_time' => now(),
            'status' => 'processed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        RawLog::query()->create([
            'device_id' => $device->id,
            'endpoint' => '/iclock/cdata',
            'payload' => 'PIN=920001',
            'parsed_data' => ['type' => 'userinfo'],
            'processing_status' => 'processed',
            'record_count' => 1,
        ]);

        ErrorLog::query()->create([
            'device_id' => $device->id,
            'type' => 'api_error',
            'message' => 'Delete test error',
            'severity' => 'medium',
            'resolved' => false,
        ]);

        DeviceCommand::query()->create([
            'device_id' => $device->id,
            'command' => 'RESTART',
            'status' => DeviceCommand::STATUS_PENDING,
        ]);

        DB::table('device_syncs')->insert([
            'device_id' => $device->id,
            'sync_type' => 'users',
            'version' => 1,
            'status' => 'queued',
            'total_records' => 0,
            'queued_records' => 0,
            'processed_records' => 0,
            'successful_records' => 0,
            'failed_records' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        EmployeeBiometric::query()->create([
            'device_id' => $device->id,
            'employee_id' => $employee->id,
            'employee_code' => $employee->employee_id,
            'biometric_type' => EmployeeBiometric::TYPE_FINGERPRINT,
            'template_index' => '0',
            'data_size' => 8,
            'template_data' => base64_encode('DELETE-ME'),
            'sync_key' => sha1('device-delete-biometric'),
        ]);

        BiometricTemplate::query()->create([
            'employee_id' => $employee->id,
            'device_id' => $device->id,
            'template_type' => BiometricTemplate::TYPE_FINGERPRINT,
            'template_index' => '0',
            'data_size' => 8,
            'template_hash' => sha1('device-delete-template'),
            'template_data' => 'DELETE-ME',
        ]);

        $response = $this->actingAs($admin)
            ->delete(route('admin.devices.destroy', $device));

        $response->assertRedirect(route('admin.devices.index'));
        $response->assertSessionHas('success', 'Device deleted successfully');

        $this->assertDatabaseMissing('devices', [
            'id' => $device->id,
        ]);

        $this->assertDatabaseHas('deleted_device_serials', [
            'serial_number' => 'DELETE-DEVICE-001',
        ]);

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'device_id' => null,
        ]);

        $this->assertDatabaseHas('attendance_logs', [
            'employee_id' => $employee->id,
            'device_id' => null,
        ]);

        $this->assertDatabaseHas('raw_logs', [
            'endpoint' => '/iclock/cdata',
            'device_id' => null,
        ]);

        $this->assertDatabaseHas('error_logs', [
            'message' => 'Delete test error',
            'device_id' => null,
        ]);

        $this->assertDatabaseMissing('device_commands', [
            'device_id' => $device->id,
        ]);

        $this->assertDatabaseMissing('device_syncs', [
            'device_id' => $device->id,
        ]);

        $this->assertDatabaseMissing('employee_biometrics', [
            'device_id' => $device->id,
        ]);

        $this->assertDatabaseMissing('biometric_templates', [
            'device_id' => $device->id,
        ]);
    }
}
