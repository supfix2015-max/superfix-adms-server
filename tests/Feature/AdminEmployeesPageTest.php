<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Services\UserSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminEmployeesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_employees_index_renders_without_missing_user_sync_route(): void
    {
        Employee::query()->create([
            'employee_id' => '991001',
            'name' => 'John Doe',
            'status' => 'active',
        ]);

        $response = $this->get(route('admin.employees.index'));

        $response->assertOk();
        $response->assertSee('Sync Employees', false);
    }

    public function test_employees_sync_endpoint_queues_batches_for_active_devices(): void
    {
        $device = Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'EMP-SYNC-001',
            'serial_number' => 'EMP-SYNC-001',
            'status' => 'active',
        ]);

        Employee::query()->create([
            'employee_id' => '991002',
            'name' => 'John Doe',
            'status' => 'active',
        ]);

        app(UserSyncService::class)->refreshDeviceSafeDataset();

        $response = $this->postJson(route('admin.user-syncs.store'), [
            'force' => false,
        ]);

        $response->assertStatus(202);
        $this->assertGreaterThan(0, $response->json('summary.active_device_count'));
        $this->assertGreaterThan(0, $response->json('summary.safe_user_count'));

        $this->assertDatabaseHas('device_syncs', [
            'device_id' => $device->id,
            'sync_type' => 'users',
        ]);
    }

    public function test_employee_show_renders_online_biometric_enrollment_controls(): void
    {
        $device = Device::query()->create([
            'name' => 'Lobby Reader',
            'sn' => 'EMP-ENROLL-001',
            'serial_number' => 'EMP-ENROLL-001',
            'status' => 'active',
            'last_seen' => now(),
            'last_heartbeat' => now(),
        ]);

        $employee = Employee::query()->create([
            'employee_id' => '991003',
            'uid' => '991003',
            'sync_uid' => '991003',
            'name' => 'John Doe',
            'status' => 'active',
            'device_id' => $device->id,
        ]);

        $response = $this->get(route('admin.employees.show', $employee));

        $response->assertOk();
        $response->assertSee('Online Biometric Enrollment', false);
        $response->assertSee(route('admin.employees.biometric-enrollment', $employee), false);
        $response->assertSee('Lobby Reader', false);
    }

    public function test_employee_biometric_enrollment_route_queues_fingerprint_command_for_online_device(): void
    {
        $device = Device::query()->create([
            'name' => 'Lobby Reader',
            'sn' => 'EMP-ENROLL-002',
            'serial_number' => 'EMP-ENROLL-002',
            'status' => 'active',
            'last_seen' => now(),
            'last_heartbeat' => now(),
        ]);

        $employee = Employee::query()->create([
            'employee_id' => '991004',
            'uid' => '991004',
            'sync_uid' => '991004',
            'name' => 'Jane Doe',
            'status' => 'active',
        ]);

        $response = $this->post(route('admin.employees.biometric-enrollment', $employee), [
            'device_id' => $device->id,
            'biometric_type' => 'fingerprint',
            'finger_index' => 2,
            'retry' => 4,
            'overwrite' => 1,
        ]);

        $response->assertRedirect(route('admin.employees.show', $employee));

        $this->assertDatabaseHas('device_commands', [
            'device_id' => $device->id,
            'employee_id' => $employee->id,
            'command' => 'ENROLL_FINGERPRINT',
            'status' => DeviceCommand::STATUS_PENDING,
        ]);

        $command = DeviceCommand::query()->latest('id')->firstOrFail();

        $this->assertSame('991004', $command->parameters['pin']);
        $this->assertSame('2', $command->parameters['fid']);
        $this->assertSame(4, $command->parameters['retry']);
        $this->assertTrue($command->parameters['overwrite']);
    }

    public function test_employee_biometric_enrollment_requires_an_online_device(): void
    {
        $device = Device::query()->create([
            'name' => 'Offline Reader',
            'sn' => 'EMP-ENROLL-003',
            'serial_number' => 'EMP-ENROLL-003',
            'status' => 'active',
        ]);

        $employee = Employee::query()->create([
            'employee_id' => '991005',
            'uid' => '991005',
            'sync_uid' => '991005',
            'name' => 'Sam Doe',
            'status' => 'active',
        ]);

        $response = $this->from(route('admin.employees.show', $employee))
            ->post(route('admin.employees.biometric-enrollment', $employee), [
                'device_id' => $device->id,
                'biometric_type' => 'face',
                'retry' => 3,
                'overwrite' => 1,
            ]);

        $response->assertRedirect(route('admin.employees.show', $employee));
        $response->assertSessionHasErrors('enrollment');

        $this->assertDatabaseCount('device_commands', 0);
    }
}
