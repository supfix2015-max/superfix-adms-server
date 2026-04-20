<?php

namespace Tests\Feature;

use App\Jobs\SyncBiometricTemplate;
use App\Models\Employee;
use App\Models\EmployeeBiometric;
use App\Models\Device;
use App\Services\BiometricSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BiometricSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_employee_templates_encrypts_the_stored_template_and_dispatches_jobs(): void
    {
        Queue::fake();

        $sourceDevice = Device::query()->create([
            'name' => 'Source Terminal',
            'serial_number' => 'SRC-100',
            'sn' => 'SRC-100',
            'ip_address' => '192.168.1.100',
            'status' => 'active',
        ]);

        $targetDevice = Device::query()->create([
            'name' => 'Target Terminal',
            'serial_number' => 'DST-101',
            'sn' => 'DST-101',
            'ip_address' => '192.168.1.101',
            'status' => 'active',
            'device_type' => 'fingerprint',
        ]);

        $employee = Employee::query()->create([
            'employee_id' => '1001',
            'device_id' => $sourceDevice->id,
            'uid' => '1001',
            'sync_uid' => '1001',
            'name' => 'Template User',
            'status' => 'active',
        ]);

        EmployeeBiometric::query()->create([
            'device_id' => $sourceDevice->id,
            'employee_id' => $employee->id,
            'employee_code' => $employee->employee_id,
            'biometric_type' => EmployeeBiometric::TYPE_FINGERPRINT,
            'template_index' => '1',
            'is_valid' => true,
            'data_size' => 18,
            'template_data' => 'PLAINTEXT_FINGER_1',
            'sync_key' => sha1('finger-1'),
        ]);

        $summary = app(BiometricSyncService::class)->syncEmployeeTemplates($employee, [
            'source_device' => $sourceDevice,
            'prefer_udp' => false,
        ]);

        $ciphertext = DB::table('biometric_templates')->value('template_data');

        $this->assertNotNull($ciphertext);
        $this->assertNotSame('PLAINTEXT_FINGER_1', (string) $ciphertext);
        $this->assertSame(1, $summary['template_count']);
        Queue::assertPushed(SyncBiometricTemplate::class, 1);
    }

    public function test_face_templates_are_flagged_for_fingerprint_only_targets(): void
    {
        Queue::fake();

        $sourceDevice = Device::query()->create([
            'name' => 'SpeedFace Source',
            'serial_number' => 'FACE-100',
            'sn' => 'FACE-100',
            'ip_address' => '192.168.1.110',
            'status' => 'active',
            'device_type' => 'face',
            'raw_config' => ['Platform' => 'SpeedFace', 'FaceAlgVersion' => '9.0'],
        ]);

        Device::query()->create([
            'name' => 'Fingerprint Target',
            'serial_number' => 'FP-200',
            'sn' => 'FP-200',
            'ip_address' => '192.168.1.120',
            'status' => 'active',
            'device_type' => 'fingerprint',
            'raw_config' => ['Platform' => 'K40'],
        ]);

        $employee = Employee::query()->create([
            'employee_id' => '1002',
            'device_id' => $sourceDevice->id,
            'uid' => '1002',
            'sync_uid' => '1002',
            'name' => 'Face User',
            'status' => 'active',
        ]);

        EmployeeBiometric::query()->create([
            'device_id' => $sourceDevice->id,
            'employee_id' => $employee->id,
            'employee_code' => $employee->employee_id,
            'biometric_type' => EmployeeBiometric::TYPE_FACE,
            'template_index' => '50',
            'is_valid' => true,
            'data_size' => 16,
            'template_data' => 'PLAINTEXT_FACE_50',
            'sync_key' => sha1('face-50'),
        ]);

        $summary = app(BiometricSyncService::class)->syncEmployeeTemplates($employee, [
            'source_device' => $sourceDevice,
            'prefer_udp' => false,
        ]);

        Queue::assertNothingPushed();
        $this->assertSame(1, $summary['skipped']);
        $this->assertDatabaseHas('error_logs', [
            'device_id' => Device::query()->where('serial_number', 'FP-200')->value('id'),
            'type' => 'queue_error',
            'error_code' => 'BIOMETRIC_SYNC_FAILED',
        ]);
    }
}
