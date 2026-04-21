<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCredentialsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_credentials_index_renders_with_current_project_data(): void
    {
        Employee::query()->create([
            'employee_id' => '991001',
            'name' => 'John Doe',
            'status' => 'active',
            'card_no' => 'CARD-991001',
            'device_password' => '2468',
        ]);

        Employee::query()->create([
            'employee_id' => '991002',
            'name' => 'Jane Doe',
            'status' => 'active',
        ]);

        $response = $this->get(route('admin.credentials.index'));

        $response->assertOk();
        $response->assertSee('Configured Credentials', false);
        $response->assertSee('Online Biometric Enrollment', false);
        $response->assertSee('Queueing the enrollment command', false);
        $response->assertSee('credentialBiometricEnrollmentModal', false);
        $response->assertSee('John Doe', false);
        $response->assertSee('Jane Doe', false);
        $response->assertSee(route('admin.credentials.store'), false);
        $response->assertSee(route('admin.credentials.biometric-enrollment'), false);
    }

    public function test_store_updates_employee_card_credential_fields(): void
    {
        $employee = Employee::query()->create([
            'employee_id' => '991003',
            'name' => 'Credential User',
            'status' => 'active',
        ]);

        $response = $this->post(route('admin.credentials.store'), [
            'employee_id' => $employee->id,
            'type' => 'CARD',
            'credential_value' => 'CARD-991003#',
            'effective_date' => '2026-04-20',
            'expiration_date' => '2026-05-20',
        ]);

        $response->assertRedirect(route('admin.credentials.index'));

        $employee->refresh();

        $this->assertSame('CARD-991003', $employee->card_no);
        $this->assertSame('2026-04-20 00:00:00', $employee->getRawOriginal('valid_from'));
        $this->assertSame('2026-05-20 23:59:59', $employee->getRawOriginal('valid_until'));
    }

    public function test_destroy_clears_only_the_requested_credential_type(): void
    {
        $employee = Employee::query()->create([
            'employee_id' => '991004',
            'name' => 'Dual Credential User',
            'status' => 'active',
            'card_no' => 'CARD-991004',
            'device_password' => '2468',
            'valid_from' => '2026-04-20 00:00:00',
            'valid_until' => '2026-12-31 23:59:59',
        ]);

        $response = $this->delete(route('admin.credentials.destroy', [
            'employee' => $employee,
            'type' => 'CARD',
        ]));

        $response->assertRedirect(route('admin.credentials.index'));

        $employee->refresh();

        $this->assertNull($employee->card_no);
        $this->assertSame('2468', $employee->device_password);
        $this->assertSame('2026-04-20 00:00:00', $employee->getRawOriginal('valid_from'));
        $this->assertSame('2026-12-31 23:59:59', $employee->getRawOriginal('valid_until'));
    }

    public function test_destroy_clears_validity_dates_when_last_credential_is_removed(): void
    {
        $employee = Employee::query()->create([
            'employee_id' => '991005',
            'name' => 'Single Credential User',
            'status' => 'active',
            'card_no' => 'CARD-991005',
            'valid_from' => '2026-04-20 00:00:00',
            'valid_until' => '2026-12-31 23:59:59',
        ]);

        $response = $this->delete(route('admin.credentials.destroy', [
            'employee' => $employee,
            'type' => 'CARD',
        ]));

        $response->assertRedirect(route('admin.credentials.index'));

        $employee->refresh();

        $this->assertNull($employee->card_no);
        $this->assertNull($employee->getRawOriginal('valid_from'));
        $this->assertNull($employee->getRawOriginal('valid_until'));
    }

    public function test_credentials_page_can_queue_remote_biometric_enrollment(): void
    {
        $device = Device::query()->create([
            'name' => 'Lobby Terminal',
            'serial_number' => 'SER-201',
            'sn' => 'SER-201',
            'ip_address' => '192.168.1.201',
            'status' => 'active',
            'last_seen' => now(),
        ]);

        $employee = Employee::query()->create([
            'employee_id' => '991006',
            'device_id' => $device->id,
            'uid' => '991006',
            'sync_uid' => '991006',
            'name' => 'Enrollment User',
            'status' => 'active',
        ]);

        $response = $this->post(route('admin.credentials.biometric-enrollment'), [
            'enrollment_employee_id' => $employee->id,
            'enrollment_device_id' => $device->id,
            'biometric_type' => 'fingerprint',
            'finger_index' => 2,
            'retry' => 4,
            'overwrite' => 1,
        ]);

        $response->assertRedirect(route('admin.credentials.index').'#biometric-enrollment');

        $command = DeviceCommand::query()->latest('id')->first();

        $this->assertNotNull($command);
        $this->assertSame($device->id, $command->device_id);
        $this->assertSame($employee->id, $command->employee_id);
        $this->assertSame('ENROLL_FINGERPRINT', $command->command);
        $this->assertSame('991006', $command->parameters['pin'] ?? null);
        $this->assertSame('2', $command->parameters['fid'] ?? null);
        $this->assertSame(4, $command->parameters['retry'] ?? null);
        $this->assertTrue((bool) ($command->parameters['overwrite'] ?? false));
    }

    public function test_credentials_page_biometric_enrollment_supports_json_visualizer_flow(): void
    {
        $device = Device::query()->create([
            'name' => 'Lobby Terminal',
            'serial_number' => 'SER-202',
            'sn' => 'SER-202',
            'ip_address' => '192.168.1.202',
            'status' => 'active',
            'last_seen' => now(),
            'last_heartbeat' => now(),
        ]);

        $employee = Employee::query()->create([
            'employee_id' => '991007',
            'device_id' => $device->id,
            'uid' => '991007',
            'sync_uid' => '991007',
            'name' => 'Credential JSON User',
            'status' => 'active',
        ]);

        $response = $this->postJson(route('admin.credentials.biometric-enrollment'), [
            'enrollment_employee_id' => $employee->id,
            'enrollment_device_id' => $device->id,
            'biometric_type' => 'face',
            'retry' => 3,
            'overwrite' => 1,
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('status.phase', 'queued');
        $response->assertJsonPath('status.biometric_type', 'face');
        $response->assertJsonPath('status.template_index', '50');
        $response->assertJsonPath('status.employee.id', $employee->id);
        $response->assertJsonPath('status.device.id', $device->id);

        $command = DeviceCommand::query()->latest('id')->firstOrFail();

        $this->assertSame('face', $command->parameters['biometric_type']);
        $this->assertSame('50', $command->parameters['template_index']);
        $this->assertStringContainsString(
            route('admin.employees.biometric-enrollment-status', [$employee, $command]),
            $response->json('status_url')
        );
    }
}
