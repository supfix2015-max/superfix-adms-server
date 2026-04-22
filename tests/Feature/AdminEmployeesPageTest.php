<?php

namespace Tests\Feature;

use App\Models\BiometricTemplate;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\EmployeeBiometric;
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
        $response->assertSee('Sync Face Templates', false);
        $response->assertSee('Sync Finger Templates', false);
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
            'scope' => UserSyncService::SCOPE_EMPLOYEES,
        ]);

        $response->assertStatus(202);
        $this->assertGreaterThan(0, $response->json('summary.active_device_count'));
        $this->assertGreaterThan(0, $response->json('summary.safe_user_count'));
        $this->assertSame(UserSyncService::SCOPE_EMPLOYEES, $response->json('summary.scope'));

        $this->assertDatabaseHas('device_syncs', [
            'device_id' => $device->id,
            'sync_type' => 'users',
        ]);
    }

    public function test_employee_store_keeps_uid_and_sync_uid_aligned(): void
    {
        $response = $this->post(route('admin.employees.store'), [
            'employee_id' => '991020',
            'uid' => '',
            'card_no' => '',
            'device_password' => '',
            'device_privilege' => 0,
            'group_code' => '',
            'timezone_code' => '',
            'verification_mode' => '',
            'vice_card_no' => '',
            'valid_from' => '',
            'valid_until' => '',
            'status' => 'active',
            'first_name' => 'Ayesha',
            'last_name' => 'Rahman',
            'email' => '',
            'phone' => '',
            'department' => '',
            'hire_date' => '',
            'notes' => '',
        ]);

        $employee = Employee::query()->where('employee_id', '991020')->firstOrFail();

        $response->assertRedirect(route('admin.employees.show', $employee));
        $this->assertSame('991020', $employee->uid);
        $this->assertSame('991020', $employee->sync_uid);
        $this->assertSame('Ayesha Rahman', $employee->name);
        $this->assertSame('Ayesha Rahman', $employee->sync_name);
    }

    public function test_employee_update_keeps_name_and_uid_sync_columns_aligned(): void
    {
        $employee = Employee::query()->create([
            'employee_id' => '991021',
            'uid' => '991021',
            'sync_uid' => '991021',
            'name' => 'Old Name',
            'sync_name' => 'Old Name',
            'status' => 'active',
        ]);

        $response = $this->put(route('admin.employees.update', $employee), [
            'uid' => '123456',
            'card_no' => '',
            'device_password' => '',
            'device_privilege' => 0,
            'group_code' => '',
            'timezone_code' => '',
            'verification_mode' => '',
            'vice_card_no' => '',
            'valid_from' => '',
            'valid_until' => '',
            'first_name' => 'Sharif',
            'last_name' => 'Hasan',
            'email' => '',
            'phone' => '',
            'department' => '',
            'status' => 'active',
            'hire_date' => '',
            'notes' => '',
        ]);

        $response->assertRedirect(route('admin.employees.show', $employee));

        $employee->refresh();

        $this->assertSame('123456', $employee->uid);
        $this->assertSame('123456', $employee->sync_uid);
        $this->assertSame('Sharif Hasan', $employee->name);
        $this->assertSame('Sharif Hasan', $employee->sync_name);
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
        $response->assertSee('Push Stored Biometrics', false);
        $response->assertSee('Biometric Replay Audit', false);
        $response->assertSee('Queueing the enrollment command', false);
        $response->assertSee('employeeBiometricEnrollmentModal', false);
        $response->assertSee(route('admin.employees.biometric-enrollment', $employee), false);
        $response->assertSee(route('admin.employees.push-biometrics', $employee), false);
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

    public function test_employee_biometric_enrollment_route_supports_json_visualizer_flow(): void
    {
        $device = Device::query()->create([
            'name' => 'Front Desk Reader',
            'sn' => 'EMP-ENROLL-JSON-001',
            'serial_number' => 'EMP-ENROLL-JSON-001',
            'status' => 'active',
            'last_seen' => now(),
            'last_heartbeat' => now(),
        ]);

        $employee = Employee::query()->create([
            'employee_id' => '991004A',
            'uid' => '991004',
            'sync_uid' => '991004',
            'name' => 'JSON User',
            'status' => 'active',
        ]);

        $response = $this->postJson(route('admin.employees.biometric-enrollment', $employee), [
            'device_id' => $device->id,
            'biometric_type' => 'fingerprint',
            'finger_index' => 3,
            'retry' => 5,
            'overwrite' => 1,
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('status.phase', 'queued');
        $response->assertJsonPath('status.biometric_type', EmployeeBiometric::TYPE_FINGERPRINT);
        $response->assertJsonPath('status.template_index', '3');
        $response->assertJsonPath('status.employee.id', $employee->id);
        $response->assertJsonPath('status.device.id', $device->id);
        $this->assertStringContainsString(
            route('admin.employees.biometric-enrollment-status', [$employee, DeviceCommand::query()->latest('id')->firstOrFail()]),
            $response->json('status_url')
        );

        $command = DeviceCommand::query()->latest('id')->firstOrFail();

        $this->assertSame('fingerprint', $command->parameters['biometric_type']);
        $this->assertSame('3', $command->parameters['template_index']);
    }

    public function test_employee_delete_force_removes_local_records_and_queues_device_cleanup_commands(): void
    {
        $deviceA = Device::query()->create([
            'name' => 'Gate A',
            'sn' => 'EMP-DELETE-001',
            'serial_number' => 'EMP-DELETE-001',
            'status' => 'active',
        ]);

        $deviceB = Device::query()->create([
            'name' => 'Gate B',
            'sn' => 'EMP-DELETE-002',
            'serial_number' => 'EMP-DELETE-002',
            'status' => 'active',
        ]);

        $employee = Employee::query()->create([
            'employee_id' => '991099',
            'uid' => '991099',
            'sync_uid' => '991099',
            'name' => 'Delete User',
            'status' => 'active',
            'device_id' => $deviceA->id,
        ]);

        EmployeeBiometric::query()->create([
            'device_id' => $deviceA->id,
            'employee_id' => $employee->id,
            'employee_code' => '991099',
            'biometric_type' => EmployeeBiometric::TYPE_FINGERPRINT,
            'template_index' => '1',
            'is_valid' => true,
            'data_size' => 16,
            'template_data' => base64_encode('DELETE-FP-991099'),
            'sync_key' => sha1('delete-fingerprint-991099'),
        ]);

        BiometricTemplate::query()->create([
            'employee_id' => $employee->id,
            'device_id' => $deviceA->id,
            'template_type' => BiometricTemplate::TYPE_FINGERPRINT,
            'template_index' => '1',
            'data_size' => 16,
            'template_hash' => sha1('delete-fingerprint-template'),
            'template_data' => 'DELETE-FP-991099',
        ]);

        $pendingSync = DeviceCommand::query()->create([
            'device_id' => $deviceA->id,
            'employee_id' => $employee->id,
            'command' => 'SYNC_EMPLOYEES',
            'parameters' => [
                'employee_code' => '991099',
                'pin' => '991099',
            ],
            'status' => DeviceCommand::STATUS_PENDING,
            'fingerprint' => sha1('pending-delete-sync'),
        ]);

        $response = $this->delete(route('admin.employees.destroy', $employee));

        $response->assertRedirect(route('admin.employees.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseMissing('employees', [
            'id' => $employee->id,
        ]);

        $this->assertDatabaseMissing('employee_biometrics', [
            'employee_code' => '991099',
        ]);

        $this->assertDatabaseMissing('biometric_templates', [
            'employee_id' => $employee->id,
        ]);

        $this->assertSoftDeleted('device_commands', [
            'id' => $pendingSync->id,
        ]);

        $this->assertSame(
            8,
            DeviceCommand::query()
                ->where(function ($query): void {
                    $query->where('command', 'DATA DELETE USERINFO PIN=991099')
                        ->orWhere('command', 'DATA DELETE FINGERTMP PIN=991099')
                        ->orWhere('command', 'DATA DELETE FACE PIN=991099')
                        ->orWhere('command', 'DATA DELETE USERPIC PIN=991099');
                })
                ->count(),
        );

        $this->assertDatabaseHas('device_commands', [
            'device_id' => $deviceA->id,
            'command' => 'DATA DELETE USERINFO PIN=991099',
            'status' => DeviceCommand::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('device_commands', [
            'device_id' => $deviceB->id,
            'command' => 'DATA DELETE FACE PIN=991099',
            'status' => DeviceCommand::STATUS_PENDING,
        ]);
    }

    public function test_employee_biometric_enrollment_status_reports_completed_after_template_upload(): void
    {
        $device = Device::query()->create([
            'name' => 'Enrollment Terminal',
            'sn' => 'EMP-ENROLL-STATUS-001',
            'serial_number' => 'EMP-ENROLL-STATUS-001',
            'status' => 'active',
            'last_seen' => now(),
            'last_heartbeat' => now(),
        ]);

        $employee = Employee::query()->create([
            'employee_id' => '991011',
            'uid' => '991011',
            'sync_uid' => '991011',
            'name' => 'Status User',
            'status' => 'active',
        ]);

        $command = DeviceCommand::query()->create([
            'device_id' => $device->id,
            'employee_id' => $employee->id,
            'command' => 'ENROLL_FINGERPRINT',
            'parameters' => [
                'pin' => '991011',
                'fid' => '1',
                'retry' => 3,
                'overwrite' => true,
                'biometric_type' => 'fingerprint',
                'template_index' => '1',
            ],
            'status' => DeviceCommand::STATUS_DONE,
        ]);

        $command->forceFill([
            'created_at' => now()->subSeconds(20),
            'sent_at' => now()->subSeconds(18),
            'acknowledged_at' => now()->subSeconds(10),
            'executed_at' => now()->subSeconds(10),
        ])->save();

        EmployeeBiometric::query()->create([
            'device_id' => $device->id,
            'employee_id' => $employee->id,
            'employee_code' => '991011',
            'biometric_type' => EmployeeBiometric::TYPE_FINGERPRINT,
            'template_index' => '1',
            'is_valid' => true,
            'data_size' => 14,
            'template_data' => base64_encode('STATUS991011FP'),
            'sync_key' => sha1('status-finger-991011'),
            'raw_payload' => ['FP PIN' => '991011', 'FID' => '1'],
        ]);

        $response = $this->getJson(route('admin.employees.biometric-enrollment-status', [
            'employee' => $employee,
            'command' => $command,
        ]));

        $response->assertOk();
        $response->assertJsonPath('phase', 'completed');
        $response->assertJsonPath('template_received', true);
        $response->assertJsonPath('biometric_type', EmployeeBiometric::TYPE_FINGERPRINT);
        $response->assertJsonPath('template_index', '1');
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

    public function test_employee_biometric_push_route_queues_adms_commands_for_online_targets_only(): void
    {
        $sourceDevice = Device::query()->create([
            'name' => 'Source Terminal',
            'sn' => 'EMP-PUSH-SRC',
            'serial_number' => 'EMP-PUSH-SRC',
            'status' => 'active',
            'last_seen' => now(),
            'last_heartbeat' => now(),
        ]);

        $onlineTarget = Device::query()->create([
            'name' => 'Online Target',
            'sn' => 'EMP-PUSH-ONLINE',
            'serial_number' => 'EMP-PUSH-ONLINE',
            'status' => 'active',
            'last_seen' => now(),
            'last_heartbeat' => now(),
            'device_type' => 'fingerprint',
        ]);

        $offlineTarget = Device::query()->create([
            'name' => 'Offline Target',
            'sn' => 'EMP-PUSH-OFFLINE',
            'serial_number' => 'EMP-PUSH-OFFLINE',
            'status' => 'active',
            'device_type' => 'fingerprint',
        ]);

        $employee = Employee::query()->create([
            'employee_id' => '991009',
            'uid' => '991009',
            'sync_uid' => '991009',
            'name' => 'Push User',
            'status' => 'active',
            'device_id' => $sourceDevice->id,
        ]);

        EmployeeBiometric::query()->create([
            'device_id' => $sourceDevice->id,
            'employee_id' => $employee->id,
            'employee_code' => '991009',
            'biometric_type' => EmployeeBiometric::TYPE_FINGERPRINT,
            'template_index' => '1',
            'is_valid' => true,
            'data_size' => 16,
            'template_data' => base64_encode('PUSHFINGER991009'),
            'sync_key' => sha1('push-finger-991009'),
            'raw_payload' => ['FP PIN' => '991009', 'FID' => '1', 'TMP' => base64_encode('PUSHFINGER991009')],
        ]);

        $response = $this->post(route('admin.employees.push-biometrics', $employee), [
            'template_type' => 'fingerprint',
            'online_only' => 1,
            'overwrite' => 1,
        ]);

        $response->assertRedirect(route('admin.employees.show', $employee));

        $this->assertDatabaseHas('device_commands', [
            'device_id' => $onlineTarget->id,
            'employee_id' => $employee->id,
            'command' => 'SYNC_EMPLOYEES',
        ]);

        $this->assertDatabaseHas('device_commands', [
            'device_id' => $onlineTarget->id,
            'employee_id' => $employee->id,
            'command' => 'SYNC_BIOMETRICS',
        ]);

        $this->assertDatabaseMissing('device_commands', [
            'device_id' => $offlineTarget->id,
            'employee_id' => $employee->id,
        ]);

        $this->assertDatabaseMissing('device_commands', [
            'device_id' => $sourceDevice->id,
            'employee_id' => $employee->id,
            'command' => 'SYNC_BIOMETRICS',
        ]);
    }

    public function test_employee_biometric_push_route_can_queue_face_only_replay(): void
    {
        $sourceDevice = Device::query()->create([
            'name' => 'Source Face Terminal',
            'sn' => 'EMP-PUSH-FACE-SRC',
            'serial_number' => 'EMP-PUSH-FACE-SRC',
            'status' => 'active',
            'last_seen' => now(),
            'last_heartbeat' => now(),
            'device_type' => 'face',
        ]);

        $targetDevice = Device::query()->create([
            'name' => 'Face Target',
            'sn' => 'EMP-PUSH-FACE-DST',
            'serial_number' => 'EMP-PUSH-FACE-DST',
            'status' => 'active',
            'last_seen' => now(),
            'last_heartbeat' => now(),
            'device_type' => 'face',
        ]);

        $employee = Employee::query()->create([
            'employee_id' => '991010',
            'uid' => '991010',
            'sync_uid' => '991010',
            'name' => 'Face Push User',
            'status' => 'active',
            'device_id' => $sourceDevice->id,
        ]);

        EmployeeBiometric::query()->create([
            'device_id' => $sourceDevice->id,
            'employee_id' => $employee->id,
            'employee_code' => '991010',
            'biometric_type' => EmployeeBiometric::TYPE_FINGERPRINT,
            'template_index' => '2',
            'is_valid' => true,
            'data_size' => 12,
            'template_data' => base64_encode('FINGER991010'),
            'sync_key' => sha1('push-finger-991010'),
            'raw_payload' => ['FP PIN' => '991010', 'FID' => '2'],
        ]);

        EmployeeBiometric::query()->create([
            'device_id' => $sourceDevice->id,
            'employee_id' => $employee->id,
            'employee_code' => '991010',
            'biometric_type' => EmployeeBiometric::TYPE_FACE,
            'template_index' => '50',
            'is_valid' => true,
            'data_size' => 14,
            'template_data' => base64_encode('FACE991010'),
            'sync_key' => sha1('push-face-991010'),
            'raw_payload' => ['FACE PIN' => '991010', 'FID' => '50', 'TMP' => base64_encode('FACE991010')],
        ]);

        $response = $this->post(route('admin.employees.push-biometrics', $employee), [
            'template_type' => 'face',
            'online_only' => 1,
            'overwrite' => 1,
        ]);

        $response->assertRedirect(route('admin.employees.show', $employee));

        $biometricCommand = DeviceCommand::query()
            ->where('device_id', $targetDevice->id)
            ->where('employee_id', $employee->id)
            ->where('command', 'SYNC_BIOMETRICS')
            ->firstOrFail();

        $this->assertSame(EmployeeBiometric::TYPE_FACE, $biometricCommand->parameters['biometric_type']);

        $this->assertDatabaseCount('device_commands', 2);
    }

    public function test_employee_only_sync_dispatches_user_commands_without_biometrics(): void
    {
        $device = Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'EMP-SYNC-USER-ONLY',
            'serial_number' => 'EMP-SYNC-USER-ONLY',
            'status' => 'active',
        ]);

        $employee = Employee::query()->create([
            'employee_id' => '991006',
            'uid' => '991006',
            'sync_uid' => '991006',
            'name' => 'Taylor Doe',
            'status' => 'active',
        ]);

        EmployeeBiometric::query()->create([
            'device_id' => $device->id,
            'employee_id' => $employee->id,
            'employee_code' => '991006',
            'biometric_type' => EmployeeBiometric::TYPE_FACE,
            'template_index' => '50',
            'is_valid' => true,
            'data_size' => 8,
            'template_data' => base64_encode('FACE991006'),
            'sync_key' => sha1('face-991006'),
            'raw_payload' => ['FACE PIN' => '991006', 'FID' => '50'],
        ]);

        app(UserSyncService::class)->refreshDeviceSafeDataset();

        $response = $this->postJson(route('admin.user-syncs.store'), [
            'force' => true,
            'scope' => UserSyncService::SCOPE_EMPLOYEES,
        ]);

        $response->assertStatus(202);

        $syncId = (int) $response->json('sync_ids.0');
        app(UserSyncService::class)->dispatchQueuedBatch($syncId);

        $this->assertDatabaseHas('device_commands', [
            'device_id' => $device->id,
            'employee_id' => $employee->id,
            'command' => 'SYNC_EMPLOYEES',
        ]);

        $this->assertDatabaseMissing('device_commands', [
            'device_id' => $device->id,
            'employee_id' => $employee->id,
            'command' => 'SYNC_BIOMETRICS',
        ]);
    }

    public function test_face_template_sync_dispatches_only_face_templates(): void
    {
        $device = Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'EMP-SYNC-FACE',
            'serial_number' => 'EMP-SYNC-FACE',
            'status' => 'active',
        ]);

        $employee = Employee::query()->create([
            'employee_id' => '991007',
            'uid' => '991007',
            'sync_uid' => '991007',
            'name' => 'Jamie Doe',
            'status' => 'active',
        ]);

        EmployeeBiometric::query()->create([
            'device_id' => $device->id,
            'employee_id' => $employee->id,
            'employee_code' => '991007',
            'biometric_type' => EmployeeBiometric::TYPE_FINGERPRINT,
            'template_index' => '1',
            'is_valid' => true,
            'data_size' => 10,
            'template_data' => base64_encode('FINGER991007'),
            'sync_key' => sha1('finger-991007'),
            'raw_payload' => ['FP PIN' => '991007', 'FID' => '1'],
        ]);

        EmployeeBiometric::query()->create([
            'device_id' => $device->id,
            'employee_id' => $employee->id,
            'employee_code' => '991007',
            'biometric_type' => EmployeeBiometric::TYPE_FACE,
            'template_index' => '50',
            'is_valid' => true,
            'data_size' => 8,
            'template_data' => base64_encode('FACE991007'),
            'sync_key' => sha1('face-991007'),
            'raw_payload' => ['FACE PIN' => '991007', 'FID' => '50'],
        ]);

        app(UserSyncService::class)->refreshDeviceSafeDataset();

        $response = $this->postJson(route('admin.user-syncs.store'), [
            'force' => true,
            'scope' => UserSyncService::SCOPE_FACE,
        ]);

        $response->assertStatus(202);
        $this->assertSame(UserSyncService::SCOPE_FACE, $response->json('summary.scope'));
        $this->assertSame(1, $response->json('summary.matching_biometric_count'));

        $syncId = (int) $response->json('sync_ids.0');
        app(UserSyncService::class)->dispatchQueuedBatch($syncId);

        $faceCommand = DeviceCommand::query()
            ->where('device_id', $device->id)
            ->where('employee_id', $employee->id)
            ->where('command', 'SYNC_BIOMETRICS')
            ->firstOrFail();

        $this->assertSame(EmployeeBiometric::TYPE_FACE, $faceCommand->parameters['biometric_type']);

        $this->assertDatabaseCount('device_commands', 2);
    }

    public function test_fingerprint_template_sync_dispatches_only_fingerprint_templates(): void
    {
        $device = Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'EMP-SYNC-FINGER',
            'serial_number' => 'EMP-SYNC-FINGER',
            'status' => 'active',
        ]);

        $employee = Employee::query()->create([
            'employee_id' => '991008',
            'uid' => '991008',
            'sync_uid' => '991008',
            'name' => 'Robin Doe',
            'status' => 'active',
        ]);

        EmployeeBiometric::query()->create([
            'device_id' => $device->id,
            'employee_id' => $employee->id,
            'employee_code' => '991008',
            'biometric_type' => EmployeeBiometric::TYPE_FINGERPRINT,
            'template_index' => '2',
            'is_valid' => true,
            'data_size' => 12,
            'template_data' => base64_encode('FINGER991008'),
            'sync_key' => sha1('finger-991008'),
            'raw_payload' => ['FP PIN' => '991008', 'FID' => '2'],
        ]);

        EmployeeBiometric::query()->create([
            'device_id' => $device->id,
            'employee_id' => $employee->id,
            'employee_code' => '991008',
            'biometric_type' => EmployeeBiometric::TYPE_FACE,
            'template_index' => '50',
            'is_valid' => true,
            'data_size' => 8,
            'template_data' => base64_encode('FACE991008'),
            'sync_key' => sha1('face-991008'),
            'raw_payload' => ['FACE PIN' => '991008', 'FID' => '50'],
        ]);

        app(UserSyncService::class)->refreshDeviceSafeDataset();

        $response = $this->postJson(route('admin.user-syncs.store'), [
            'force' => true,
            'scope' => UserSyncService::SCOPE_FINGERPRINT,
        ]);

        $response->assertStatus(202);
        $this->assertSame(UserSyncService::SCOPE_FINGERPRINT, $response->json('summary.scope'));
        $this->assertSame(1, $response->json('summary.matching_biometric_count'));

        $syncId = (int) $response->json('sync_ids.0');
        app(UserSyncService::class)->dispatchQueuedBatch($syncId);

        $fingerCommand = DeviceCommand::query()
            ->where('device_id', $device->id)
            ->where('employee_id', $employee->id)
            ->where('command', 'SYNC_BIOMETRICS')
            ->firstOrFail();

        $this->assertSame(EmployeeBiometric::TYPE_FINGERPRINT, $fingerCommand->parameters['biometric_type']);

        $this->assertDatabaseCount('device_commands', 2);
    }
}
