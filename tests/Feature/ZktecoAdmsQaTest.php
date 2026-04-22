<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\EmployeeBiometric;
use App\Services\DeviceService;
use App\Services\UserSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ZktecoAdmsQaTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_registration_creates_device_and_updates_last_seen(): void
    {
        Carbon::setTestNow('2026-04-17 08:00:00');

        $response = $this->get('/iclock/cdata?SN=TEST123&options=all');

        $response->assertOk();

        $device = Device::query()->where('serial_number', 'TEST123')->firstOrFail();

        $this->assertNotNull($device->last_seen);
        $this->assertSame('2026-04-17 08:00:00', $device->last_seen?->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('raw_logs', [
            'device_id' => $device->id,
            'endpoint' => '/iclock/cdata',
            'processing_status' => 'processed',
        ]);
    }

    public function test_option_handshake_uses_system_timezone_offset_when_no_static_timezone_is_configured(): void
    {
        config()->set('zkteco.system_timezone', 'Asia/Dhaka');
        config()->set('zkteco.option_timezone', null);

        $response = $this->get('/iclock/cdata?SN=TEST123&options=all');

        $response->assertOk();
        $response->assertSee('TimeZone=6', false);
    }

    public function test_soft_deleted_device_is_restored_during_handshake_instead_of_causing_duplicate_insert(): void
    {
        $device = Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'TEST123',
            'serial_number' => 'TEST123',
            'status' => 'active',
        ]);

        $device->delete();

        $response = $this->get('/iclock/cdata?SN=TEST123');

        $response->assertOk();
        $response->assertSee('GET OPTION FROM: TEST123', false);

        $restoredDevice = Device::query()->where('serial_number', 'TEST123')->firstOrFail();

        $this->assertSame($device->id, $restoredDevice->id);
        $this->assertSame(1, Device::withTrashed()->where('serial_number', 'TEST123')->count());
    }

    public function test_configured_device_endpoint_is_used_instead_of_request_source_address(): void
    {
        config()->set('zkteco.device_endpoints', [
            'TEST123' => [
                'ip_address' => '192.168.1.202',
                'port' => 8000,
            ],
        ]);

        $device = Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'TEST123',
            'serial_number' => 'TEST123',
            'status' => 'active',
            'ip_address' => '127.0.0.1',
            'port' => 80,
        ]);

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
        ])->get('/iclock/cdata?SN=TEST123&options=all');

        $response->assertOk();

        $device->refresh();

        $this->assertSame('192.168.1.202', $device->ip_address);
        $this->assertSame(8000, $device->port);
    }

    public function test_attendance_upload_stores_logs_and_retries_do_not_duplicate_records(): void
    {
        Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'TEST123',
            'serial_number' => 'TEST123',
            'status' => 'active',
        ]);

        $payload = implode("\r\n", [
            "1\t2026-04-17 09:00:00\t0",
            "2\t2026-04-17 09:05:00\t1",
        ]);

        $first = $this->call(
            'POST',
            '/iclock/cdata?SN=TEST123&table=ATTLOG&Stamp=1',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            $payload,
        );

        $retry = $this->call(
            'POST',
            '/iclock/cdata?SN=TEST123&table=ATTLOG&Stamp=2',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            $payload,
        );

        $first->assertOk();
        $first->assertSee('OK:2', false);
        $retry->assertOk();
        $retry->assertSee('OK:0', false);

        $this->assertDatabaseCount('attendance_logs', 2);
        $this->assertDatabaseHas('attendance_logs', [
            'employee_code' => '1',
            'log_type' => 'checkin',
            'verify_type' => 'unknown',
        ]);
        $this->assertDatabaseHas('attendance_logs', [
            'employee_code' => '2',
            'log_type' => 'checkout',
            'verify_type' => 'unknown',
        ]);
    }

    public function test_get_cdata_can_ingest_attendance_payload_for_firmware_variants(): void
    {
        Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'TEST123',
            'serial_number' => 'TEST123',
            'status' => 'active',
        ]);

        $response = $this->call(
            'GET',
            '/iclock/cdata?SN=TEST123&table=ATTLOG&Stamp=9',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            "3\t2026-04-17 09:10:00\t0",
        );

        $response->assertOk();
        $response->assertSee('OK:1', false);
        $this->assertDatabaseHas('attendance_logs', [
            'employee_code' => '3',
            'log_type' => 'checkin',
        ]);
    }

    public function test_getrequest_returns_a_pending_command_and_marks_it_sent(): void
    {
        $device = Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'TEST123',
            'serial_number' => 'TEST123',
            'status' => 'active',
        ]);

        $command = DeviceCommand::query()->create([
            'device_id' => $device->id,
            'command' => 'RESTART',
            'status' => 'pending',
        ]);

        $response = $this->get('/iclock/getrequest?SN=TEST123');

        $response->assertOk();
        $response->assertSee("C:{$command->id}:REBOOT", false);

        $this->assertDatabaseHas('device_commands', [
            'id' => $command->id,
            'status' => 'sent',
        ]);
    }

    public function test_getrequest_auto_queues_pull_employees_for_devices_without_synced_users(): void
    {
        $device = Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'TEST123',
            'serial_number' => 'TEST123',
            'status' => 'active',
        ]);

        $response = $this->get('/iclock/getrequest?SN=TEST123');

        $response->assertOk();

        $command = DeviceCommand::query()
            ->where('device_id', $device->id)
            ->where('command', 'PULL_EMPLOYEES')
            ->firstOrFail();

        $response->assertSee("C:{$command->id}:DATA QUERY USERINFO", false);

        $this->assertDatabaseHas('device_commands', [
            'id' => $command->id,
            'status' => DeviceCommand::STATUS_SENT,
        ]);

        $this->get('/iclock/getrequest?SN=TEST123')->assertOk()->assertSee('OK', false);
        $this->assertSame(1, DeviceCommand::query()->where('device_id', $device->id)->where('command', 'PULL_EMPLOYEES')->count());
    }

    public function test_raw_zkteco_command_strings_can_be_inserted_directly_into_the_command_queue(): void
    {
        $device = Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'TEST123',
            'serial_number' => 'TEST123',
            'status' => 'active',
        ]);

        $command = DeviceCommand::query()->create([
            'device_id' => $device->id,
            'command' => 'DATA USER 1 1 John 0 1234 0',
            'status' => DeviceCommand::STATUS_PENDING,
        ]);

        $response = $this->get('/iclock/getrequest?SN=TEST123');

        $response->assertOk();
        $response->assertSee("C:{$command->id}:DATA USER 1 1 John 0 1234 0", false);
        $this->assertDatabaseHas('device_commands', [
            'id' => $command->id,
            'status' => DeviceCommand::STATUS_SENT,
        ]);
    }

    public function test_device_command_callback_marks_command_done_and_stores_response_payload(): void
    {
        $device = Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'TEST123',
            'serial_number' => 'TEST123',
            'status' => 'active',
        ]);

        $command = DeviceCommand::query()->create([
            'device_id' => $device->id,
            'command' => 'RESTART',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $response = $this->call(
            'POST',
            '/iclock/devicecmd?SN=TEST123',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            "ID={$command->id}\nReturn=OK",
        );

        $response->assertOk();

        $command->refresh();

        $this->assertSame('done', $command->status);
        $this->assertSame("ID={$command->id}\nReturn=OK", $command->response);
        $this->assertSame('OK', $command->response_payload['Return']);
    }

    public function test_user_sync_can_be_queued_fetched_and_acknowledged(): void
    {
        $device = Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'TEST123',
            'serial_number' => 'TEST123',
            'status' => 'active',
        ]);

        $command = app(DeviceService::class)->queueDeviceCommand($device->id, 'SYNC_EMPLOYEES', [
            'employee_code' => '1',
            'pin' => '1',
            'name' => 'John',
            'card_no' => '1234',
            'privilege' => 0,
        ]);

        $getRequest = $this->get('/iclock/getrequest?SN=TEST123');
        $getRequest->assertOk();
        $getRequest->assertSee("C:{$command->id}:DATA UPDATE USERINFO PIN=1", false);
        $getRequest->assertSee('Name=John', false);
        $getRequest->assertSee('Card=1234', false);

        $deviceReply = $this->call(
            'POST',
            '/iclock/devicecmd?SN=TEST123',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            "ID={$command->id}\nReturn=OK\nCMD=DATA UPDATE USERINFO",
        );

        $deviceReply->assertOk();

        $this->assertDatabaseHas('device_commands', [
            'id' => $command->id,
            'status' => 'done',
            'response' => 'DATA UPDATE USERINFO',
        ]);
    }

    public function test_failed_biometric_callback_creates_rejection_error_log(): void
    {
        $device = Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'BIOREJECT123',
            'serial_number' => 'BIOREJECT123',
            'status' => 'active',
        ]);

        $employee = Employee::query()->create([
            'employee_id' => '1009',
            'uid' => '1009',
            'sync_uid' => '1009',
            'name' => 'Reject User',
            'status' => 'active',
        ]);

        $command = DeviceCommand::query()->create([
            'device_id' => $device->id,
            'employee_id' => $employee->id,
            'command' => 'SYNC_BIOMETRICS',
            'parameters' => [
                'employee_code' => '1009',
                'pin' => '1009',
                'biometric_type' => 'face',
                'template_index' => '50',
            ],
            'status' => DeviceCommand::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $response = $this->call(
            'POST',
            '/iclock/devicecmd?SN=BIOREJECT123',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            "ID={$command->id}\nReturn=ERROR\nCMD=DATA UPDATE FACE\nMessage=Verification failed",
        );

        $response->assertOk();

        $command->refresh();

        $this->assertSame(DeviceCommand::STATUS_PENDING, $command->status);
        $this->assertSame('Verification failed', $command->error_message);
        $this->assertDatabaseHas('error_logs', [
            'device_id' => $device->id,
            'type' => 'queue_error',
            'error_code' => 'BIOMETRIC_PUSH_REJECTED',
            'resolved' => false,
        ]);
    }

    public function test_device_command_callback_completes_user_sync_batch_progress(): void
    {
        $device = Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'SYNCBATCH123',
            'serial_number' => 'SYNCBATCH123',
            'status' => 'active',
        ]);

        $employee = Employee::query()->create([
            'device_id' => $device->id,
            'employee_id' => '1001',
            'uid' => '1001',
            'sync_uid' => '1001',
            'name' => 'John Sync',
            'sync_name' => 'John Sync',
            'status' => 'active',
            'sync_enabled' => true,
        ]);

        app(UserSyncService::class)->refreshDeviceSafeDataset(collect([$employee->fresh()]));

        $sync = app(UserSyncService::class)->queueFullSyncForDevice($device, true, 'qa');
        $this->assertNotNull($sync);

        app(UserSyncService::class)->dispatchQueuedBatch($sync->id);

        $command = DeviceCommand::query()
            ->where('device_sync_id', $sync->id)
            ->where('command', 'SYNC_EMPLOYEES')
            ->firstOrFail();

        $this->get('/iclock/getrequest?SN=SYNCBATCH123')->assertOk();

        $this->call(
            'POST',
            '/iclock/devicecmd?SN=SYNCBATCH123',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            "ID={$command->id}\nReturn=OK\nCMD=DATA UPDATE USERINFO",
        )->assertOk();

        $sync->refresh();
        $device->refresh();

        $this->assertSame('completed', $sync->status);
        $this->assertSame(1, $sync->queued_records);
        $this->assertSame(1, $sync->processed_records);
        $this->assertSame(1, $sync->successful_records);
        $this->assertSame(0, $sync->failed_records);
        $this->assertSame($sync->version, $device->last_user_sync_version);
        $this->assertFalse($device->pending_full_resync);
    }

    public function test_pull_employees_command_requests_device_data_and_userinfo_upload_persists_it(): void
    {
        $device = Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'TEST123',
            'serial_number' => 'TEST123',
            'status' => 'active',
        ]);

        $command = app(DeviceService::class)->queuePullEmployees($device->id);

        $poll = $this->get('/iclock/getrequest?SN=TEST123');
        $poll->assertOk();
        $poll->assertSee("C:{$command->id}:DATA QUERY USERINFO", false);

        $upload = $this->call(
            'POST',
            '/iclock/cdata?SN=TEST123&table=USERINFO&Stamp=10',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            "PIN=9001\tName=John Doe\tCard=1234\tUID=9001\nPIN=9002\tName=Jane Smith\tCard=5678\tUID=9002",
        );

        $upload->assertOk();
        $upload->assertSee('OK:2', false);

        $command->refresh();

        $this->assertDatabaseHas('employees', [
            'device_id' => $device->id,
            'employee_id' => '9001',
            'name' => 'John Doe',
            'card_no' => '1234',
        ]);
        $this->assertDatabaseHas('employees', [
            'device_id' => $device->id,
            'employee_id' => '9002',
            'name' => 'Jane Smith',
            'card_no' => '5678',
        ]);
        $this->assertSame(DeviceCommand::STATUS_DONE, $command->status);
    }

    public function test_pull_employees_upload_does_not_replace_existing_name_with_generated_device_name(): void
    {
        $device = Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'TEST123',
            'serial_number' => 'TEST123',
            'status' => 'active',
        ]);

        Employee::query()->create([
            'device_id' => $device->id,
            'employee_id' => '9001',
            'uid' => '9001',
            'sync_uid' => '9001',
            'name' => 'Abdul Karim',
            'sync_name' => 'Abdul Karim',
            'status' => 'active',
        ]);

        $command = app(DeviceService::class)->queuePullEmployees($device->id);

        $this->get('/iclock/getrequest?SN=TEST123')->assertOk();

        $upload = $this->call(
            'POST',
            '/iclock/cdata?SN=TEST123&table=USERINFO&Stamp=11',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            "PIN=9001\tName=User 9001\tCard=1234\tUID=9001",
        );

        $upload->assertOk();
        $upload->assertSee('OK:1', false);

        $this->assertDatabaseHas('employees', [
            'device_id' => $device->id,
            'employee_id' => '9001',
            'uid' => '9001',
            'sync_uid' => '9001',
            'name' => 'Abdul Karim',
            'sync_name' => 'Abdul Karim',
            'card_no' => '1234',
        ]);

        $command->refresh();
        $this->assertSame(DeviceCommand::STATUS_DONE, $command->status);
    }

    public function test_operlog_user_payload_is_ignored_for_employee_creation(): void
    {
        Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'TEST123',
            'serial_number' => 'TEST123',
            'status' => 'active',
        ]);

        $payload = implode("\n", [
            "USER PIN=1\tName=User 1\tPri=0\tPasswd=\tCard=\tGrp=1\tTZ=0000000100000000\tVerify=-1\tViceCard=\tExpires=0\tStartDatetime=0\tEndDatetime=0",
            "USER PIN=1001\tName=User 1001\tPri=0\tPasswd=\tCard=1234\tGrp=1\tTZ=0000000100000000\tVerify=-1\tViceCard=\tExpires=0\tStartDatetime=0\tEndDatetime=0",
        ]);

        $response = $this->call(
            'POST',
            '/iclock/cdata?SN=TEST123&table=OPERLOG&Stamp=10',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            $payload,
        );

        $response->assertOk();
        $response->assertSee('OK:0', false);

        $this->assertDatabaseMissing('employees', [
            'name' => 'User 1',
        ]);
        $this->assertDatabaseMissing('employees', [
            'name' => 'User 1001',
        ]);
        $this->assertDatabaseHas('raw_logs', [
            'endpoint' => '/iclock/cdata',
            'processing_status' => 'processed',
            'record_count' => 0,
        ]);
    }

    public function test_cleanup_legacy_malformed_users_removes_user_oplog_rows(): void
    {
        $legacy = Employee::query()->create([
            'employee_id' => 'OPLOG71',
            'name' => 'User OPLOG71',
            'status' => 'active',
            'raw_payload' => [
                'PIN' => 'OPLOG71',
                'Name' => 'User OPLOG71',
            ],
        ]);

        $kept = Employee::query()->create([
            'employee_id' => '1001',
            'name' => 'John Doe',
            'status' => 'active',
            'raw_payload' => [
                'PIN' => '1001',
                'Name' => 'John Doe',
            ],
        ]);

        $deleted = app(UserSyncService::class)->cleanupLegacyMalformedUsers();

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('employees', ['id' => $legacy->id]);
        $this->assertDatabaseHas('employees', ['id' => $kept->id, 'name' => 'John Doe']);
    }

    public function test_oplog_attendance_payload_is_ignored_and_does_not_create_employees(): void
    {
        Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'TEST123',
            'serial_number' => 'TEST123',
            'status' => 'active',
        ]);

        Employee::query()->create([
            'employee_id' => 'OPLOG71',
            'name' => 'User OPLOG71',
            'status' => 'active',
            'raw_payload' => [
                'PIN' => 'OPLOG71',
                'Name' => 'User OPLOG71',
            ],
        ]);

        $response = $this->call(
            'POST',
            '/iclock/cdata?SN=TEST123&table=ATTLOG&Stamp=11',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            "OPLOG 71\t0\t2026-04-21 11:40:18\t1\t0\t0\t0",
        );

        $response->assertOk();
        $response->assertSee('OK:0', false);

        $this->assertDatabaseMissing('employees', [
            'employee_id' => 'OPLOG71',
        ]);
        $this->assertDatabaseMissing('attendance_logs', [
            'employee_code' => 'OPLOG71',
        ]);
    }

    public function test_biometric_payload_is_persisted_and_updates_device_counters(): void
    {
                $payload = implode("\n", [
            'FP PIN=1001	FID=0	Size=16	Valid=1	TMP=FINGERPRINT_TEMPLATE',
            'FACE PIN=1001	FID=50	Size=24	Valid=1	TMP=FACE_TEMPLATE',
            'USERPIC PIN=1001	FileName=1001.jpg	Size=12	Content=PHOTO_TEMPLATE',
        ]);

        $response = $this->call(
            'POST',
            '/iclock/cdata?SN=TEST123&table=OPERLOG&Stamp=11',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            $payload,
        );

        $response->assertOk();
        $response->assertSee('OK:3', false);

        $this->assertDatabaseHas('employee_biometrics', [
            'device_id' => $device->id,
            'employee_id' => $employee->id,
            'employee_code' => '1001',
            'biometric_type' => EmployeeBiometric::TYPE_FINGERPRINT,
            'template_index' => '0',
        ]);
        $this->assertDatabaseHas('employee_biometrics', [
            'device_id' => $device->id,
            'employee_id' => $employee->id,
            'employee_code' => '1001',
            'biometric_type' => EmployeeBiometric::TYPE_FACE,
            'template_index' => '50',
        ]);
        $this->assertDatabaseHas('employee_biometrics', [
            'device_id' => $device->id,
            'employee_id' => $employee->id,
            'employee_code' => '1001',
            'biometric_type' => EmployeeBiometric::TYPE_PHOTO,
            'file_name' => '1001.jpg',
        ]);

        $device->refresh();

        $this->assertSame(1, $device->enrolled_fingerprints);
        $this->assertSame(1, $device->enrolled_faces);
    }

    public function test_biodata_fingerprint_payload_is_persisted_after_enrollment_callback(): void
    {
        $device = Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'BIODATA123',
            'serial_number' => 'BIODATA123',
            'status' => 'active',
        ]);

        $employee = Employee::query()->create([
            'device_id' => $device->id,
            'employee_id' => '1002',
            'uid' => '1002',
            'sync_uid' => '1002',
            'name' => 'Biodata User',
            'sync_name' => 'Biodata User',
            'status' => 'active',
            'sync_enabled' => true,
        ]);

        $payload = 'BIODATA Pin=1002	No=9	Index=0	Valid=1	Duress=0	Type=1	MajorVer=13	MinorVer=0	Format=0	Tmp=BIODATA_TEMPLATE';

        $response = $this->call(
            'POST',
            '/iclock/cdata?SN=BIODATA123&table=OPERLOG&Stamp=11',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            $payload,
        );

        $response->assertOk();
        $response->assertSee('OK:1', false);

        $this->assertDatabaseHas('employee_biometrics', [
            'device_id' => $device->id,
            'employee_id' => $employee->id,
            'employee_code' => '1002',
            'biometric_type' => EmployeeBiometric::TYPE_FINGERPRINT,
            'template_index' => '0',
            'template_data' => 'BIODATA_TEMPLATE',
        ]);
    }

    public function test_biometric_payload_still_succeeds_when_biometric_template_store_is_not_migrated(): void
    {
        Schema::dropIfExists('biometric_templates');

        $device = Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'NOBIOTABLE123',
            'serial_number' => 'NOBIOTABLE123',
            'status' => 'active',
        ]);

        $employee = Employee::query()->create([
            'device_id' => $device->id,
            'employee_id' => '1001',
            'uid' => '1001',
            'sync_uid' => '1001',
            'name' => 'John Doe',
            'sync_name' => 'John Doe',
            'status' => 'active',
            'sync_enabled' => true,
        ]);

        $response = $this->call(
            'POST',
            '/iclock/cdata?SN=NOBIOTABLE123&table=OPERLOG&Stamp=11',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            'FP PIN=1001	FID=0	Size=16	Valid=1	TMP=FINGERPRINT_TEMPLATE',
        );

        $response->assertOk();
        $response->assertSee('OK:1', false);

        $this->assertDatabaseHas('employee_biometrics', [
            'device_id' => $device->id,
            'employee_id' => $employee->id,
            'employee_code' => '1001',
            'biometric_type' => EmployeeBiometric::TYPE_FINGERPRINT,
            'template_index' => '0',
        ]);
    }

    public function test_full_sync_commands_include_device_credentials(): void
    {
        $device = Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'TEST123',
            'serial_number' => 'TEST123',
            'status' => 'active',
        ]);

        $employee = Employee::query()->create([
            'device_id' => $device->id,
            'employee_id' => '1001',
            'uid' => '1001',
            'sync_uid' => '1001',
            'card_no' => '7788',
            'device_password' => '2468',
            'device_privilege' => 3,
            'group_code' => '1',
            'timezone_code' => '0000000100000000',
            'verification_mode' => '-1',
            'vice_card_no' => 'ABCD',
            'valid_from' => '2026-04-17 00:00:00',
            'valid_until' => '2026-12-31 00:00:00',
            'name' => 'John Device',
            'sync_name' => 'John Device',
            'status' => 'active',
            'sync_enabled' => true,
        ]);

        app(UserSyncService::class)->refreshDeviceSafeDataset(collect([$employee->fresh()]));
        $sync = app(UserSyncService::class)->queueFullSyncForDevice($device, true, 'qa');
        $this->assertNotNull($sync);
        app(UserSyncService::class)->dispatchQueuedBatch($sync->id);

        $command = DeviceCommand::query()
            ->where('device_id', $device->id)
            ->where('command', 'SYNC_EMPLOYEES')
            ->firstOrFail();

        $response = $this->get('/iclock/getrequest?SN=TEST123');

        $response->assertOk();
        $response->assertSee("C:{$command->id}:DATA UPDATE USERINFO PIN=1001", false);
        $response->assertSee('Passwd=2468', false);
        $response->assertSee('Pri=3', false);
        $response->assertSee('Card=7788', false);
        $response->assertSee('Grp=1', false);
        $response->assertSee('TZ=0000000100000000', false);
        $response->assertSee('Verify=-1', false);
        $response->assertSee('ViceCard=ABCD', false);
        $response->assertSee('StartDatetime=2026-04-17 00:00:00', false);
        $response->assertSee('EndDatetime=2026-12-31 00:00:00', false);
    }

    public function test_full_sync_dispatch_includes_biometric_templates_after_userinfo(): void
    {
        $fingerprintTemplate = base64_encode('FINGERPRINT_TEMPLATE');
        $faceTemplate = base64_encode('FACE_TEMPLATE');
        $uploadedFaceTemplate = base64_encode(str_repeat('F', 16).'FACE_TEMPLATE');

        $targetDevice = Device::query()->create([
            'name' => 'Target Device',
            'sn' => 'TARGET123',
            'serial_number' => 'TARGET123',
            'status' => 'active',
        ]);

        $sourceDevice = Device::query()->create([
            'name' => 'Source Device',
            'sn' => 'SOURCE123',
            'serial_number' => 'SOURCE123',
            'status' => 'active',
        ]);

        $employee = Employee::query()->create([
            'device_id' => $sourceDevice->id,
            'employee_id' => '1001',
            'uid' => '1001',
            'sync_uid' => '1001',
            'name' => 'John Device',
            'sync_name' => 'John Device',
            'status' => 'active',
            'sync_enabled' => true,
        ]);

        EmployeeBiometric::query()->create([
            'device_id' => $sourceDevice->id,
            'employee_id' => $employee->id,
            'employee_code' => '1001',
            'biometric_type' => EmployeeBiometric::TYPE_FINGERPRINT,
            'template_index' => '0',
            'is_valid' => true,
            'data_size' => strlen($fingerprintTemplate),
            'template_data' => $fingerprintTemplate,
            'sync_key' => sha1('fingerprint-template'),
            'raw_payload' => [
                'FP PIN' => '1001',
                'FID' => '0',
                'TMP' => $fingerprintTemplate,
            ],
        ]);

        EmployeeBiometric::query()->create([
            'device_id' => $sourceDevice->id,
            'employee_id' => $employee->id,
            'employee_code' => '1001',
            'biometric_type' => EmployeeBiometric::TYPE_FACE,
            'template_index' => '50',
            'is_valid' => true,
            'data_size' => strlen($uploadedFaceTemplate),
            'template_data' => $uploadedFaceTemplate,
            'sync_key' => sha1('face-template'),
            'raw_payload' => [
                'FACE PIN' => '1001',
                'FID' => '50',
                'TMP' => $uploadedFaceTemplate,
                'face_upload_prefixed' => true,
            ],
        ]);

        app(UserSyncService::class)->refreshDeviceSafeDataset(
            collect([
                Employee::query()->with('biometrics')->findOrFail($employee->id),
            ])
        );

        $sync = app(UserSyncService::class)->queueFullSyncForDevice($targetDevice, true, 'qa');
        $this->assertNotNull($sync);

        app(UserSyncService::class)->dispatchQueuedBatch($sync->id);

        $sync->refresh();

        $this->assertSame(3, $sync->total_records);
        $this->assertSame(3, $sync->queued_records);
        $this->assertDatabaseHas('device_commands', [
            'device_id' => $targetDevice->id,
            'device_sync_id' => $sync->id,
            'employee_id' => $employee->id,
            'command' => 'SYNC_BIOMETRICS',
        ]);

        $userCommand = $this->get('/iclock/getrequest?SN=TARGET123');
        $fingerprintCommand = $this->get('/iclock/getrequest?SN=TARGET123');
        $faceCommand = $this->get('/iclock/getrequest?SN=TARGET123');

        $userCommand->assertOk();
        $userCommand->assertSee('DATA UPDATE USERINFO PIN=1001', false);

        $fingerprintCommand->assertOk();
        $fingerprintCommand->assertSee('DATA UPDATE FINGERTMP PIN=1001', false);
        $fingerprintCommand->assertSee('FID=0', false);
        $fingerprintCommand->assertSee('Size='.strlen($fingerprintTemplate), false);
        $fingerprintCommand->assertSee("TMP={$fingerprintTemplate}", false);

        $faceCommand->assertOk();
        $faceCommand->assertSee('DATA UPDATE FACE PIN=1001', false);
        $faceCommand->assertSee('FID=50', false);
        $faceCommand->assertSee('Size='.strlen($faceTemplate), false);
        $faceCommand->assertSee("TMP={$faceTemplate}", false);
    }

    public function test_full_sync_replays_plain_adms_templates_without_reencoding_them(): void
    {
        $fingerprintTemplate = 'RAW_FINGERPRINT_01';
        $faceTemplate = 'RAW_FACE_TEMPLATE_50';

        $targetDevice = Device::query()->create([
            'name' => 'Replay Target',
            'sn' => 'REPLAY-TARGET-001',
            'serial_number' => 'REPLAY-TARGET-001',
            'status' => 'active',
        ]);

        $sourceDevice = Device::query()->create([
            'name' => 'Replay Source',
            'sn' => 'REPLAY-SOURCE-001',
            'serial_number' => 'REPLAY-SOURCE-001',
            'status' => 'active',
        ]);

        $employee = Employee::query()->create([
            'device_id' => $sourceDevice->id,
            'employee_id' => '2001',
            'uid' => '2001',
            'sync_uid' => '2001',
            'name' => 'Replay User',
            'sync_name' => 'Replay User',
            'status' => 'active',
            'sync_enabled' => true,
        ]);

        EmployeeBiometric::query()->create([
            'device_id' => $sourceDevice->id,
            'employee_id' => $employee->id,
            'employee_code' => '2001',
            'biometric_type' => EmployeeBiometric::TYPE_FINGERPRINT,
            'template_index' => '1',
            'is_valid' => true,
            'data_size' => strlen($fingerprintTemplate),
            'template_data' => $fingerprintTemplate,
            'sync_key' => sha1('raw-fingerprint-template'),
            'raw_payload' => [
                'FP PIN' => '2001',
                'FID' => '1',
                'TMP' => $fingerprintTemplate,
            ],
        ]);

        EmployeeBiometric::query()->create([
            'device_id' => $sourceDevice->id,
            'employee_id' => $employee->id,
            'employee_code' => '2001',
            'biometric_type' => EmployeeBiometric::TYPE_FACE,
            'template_index' => '50',
            'is_valid' => true,
            'data_size' => strlen($faceTemplate),
            'template_data' => $faceTemplate,
            'sync_key' => sha1('raw-face-template'),
            'raw_payload' => [
                'FACE PIN' => '2001',
                'FID' => '50',
                'TMP' => $faceTemplate,
            ],
        ]);

        app(UserSyncService::class)->refreshDeviceSafeDataset(
            collect([
                Employee::query()->with('biometrics')->findOrFail($employee->id),
            ])
        );

        $sync = app(UserSyncService::class)->queueFullSyncForDevice($targetDevice, true, 'qa');
        $this->assertNotNull($sync);

        app(UserSyncService::class)->dispatchQueuedBatch($sync->id);

        $this->get('/iclock/getrequest?SN=REPLAY-TARGET-001')->assertOk();

        $fingerprintCommand = $this->get('/iclock/getrequest?SN=REPLAY-TARGET-001');
        $faceCommand = $this->get('/iclock/getrequest?SN=REPLAY-TARGET-001');

        $fingerprintCommand->assertOk();
        $fingerprintCommand->assertSee('DATA UPDATE FINGERTMP PIN=2001', false);
        $fingerprintCommand->assertSee('FID=1', false);
        $fingerprintCommand->assertSee('Size='.strlen($fingerprintTemplate), false);
        $fingerprintCommand->assertSee("TMP={$fingerprintTemplate}", false);

        $faceCommand->assertOk();
        $faceCommand->assertSee('DATA UPDATE FACE PIN=2001', false);
        $faceCommand->assertSee('FID=50', false);
        $faceCommand->assertSee('Size='.strlen($faceTemplate), false);
        $faceCommand->assertSee("TMP={$faceTemplate}", false);
    }

    public function test_remote_fingerprint_enrollment_command_is_formatted_for_device_polling(): void
    {
        $device = Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'ENROLLFP123',
            'serial_number' => 'ENROLLFP123',
            'status' => 'active',
        ]);

        $command = app(DeviceService::class)->queueDeviceCommand($device->id, 'ENROLL_FINGERPRINT', [
            'pin' => '1001',
            'fid' => '2',
            'retry' => 4,
            'overwrite' => true,
        ]);

        $response = $this->get('/iclock/getrequest?SN=ENROLLFP123');

        $response->assertOk();
        $response->assertSee("C:{$command->id}:ENROLL_FP PIN=1001", false);
        $response->assertSee('FID=2', false);
        $response->assertSee('RETRY=4', false);
        $response->assertSee('OVERWRITE=1', false);
    }

    public function test_remote_face_enrollment_command_is_formatted_for_device_polling(): void
    {
        $device = Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'ENROLLFACE123',
            'serial_number' => 'ENROLLFACE123',
            'status' => 'active',
        ]);

        $command = app(DeviceService::class)->queueDeviceCommand($device->id, 'ENROLL_FACE', [
            'pin' => '1001',
            'card_no' => '7788',
            'retry' => 3,
            'overwrite' => true,
        ]);

        $response = $this->get('/iclock/getrequest?SN=ENROLLFACE123');

        $response->assertOk();
        $response->assertSee("C:{$command->id}:ENROLL_BIO TYPE=2", false);
        $response->assertSee('PIN=1001', false);
        $response->assertSee('CardNo=7788', false);
        $response->assertSee('RETRY=3', false);
        $response->assertSee('OVERWRITE=1', false);
    }

    public function test_pull_attendance_command_is_formatted_for_device_polling(): void
    {
        $device = Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'TEST123',
            'serial_number' => 'TEST123',
            'status' => 'active',
        ]);

        $command = app(DeviceService::class)->queuePullAttendance($device->id);

        $response = $this->get('/iclock/getrequest?SN=TEST123');

        $response->assertOk();
        $response->assertSee("C:{$command->id}:DATA QUERY ATTLOG", false);
        $this->assertDatabaseHas('device_commands', [
            'id' => $command->id,
            'status' => DeviceCommand::STATUS_SENT,
        ]);
    }

    public function test_update_time_dispatch_uses_system_timezone_when_command_has_no_timestamp_parameter(): void
    {
        config()->set('app.timezone', 'UTC');
        config()->set('zkteco.system_timezone', 'Asia/Dhaka');
        Carbon::setTestNow(Carbon::parse('2026-04-17 12:00:00', 'UTC'));
        config()->set('zkteco.auto_pull_employees_on_contact', false);

        $device = Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'TEST123',
            'serial_number' => 'TEST123',
            'status' => 'active',
        ]);

        $command = DeviceCommand::query()->create([
            'device_id' => $device->id,
            'command' => 'UPDATE_TIME',
            'status' => DeviceCommand::STATUS_PENDING,
        ]);

        $response = $this->get('/iclock/getrequest?SN=TEST123');

        $response->assertOk();
        $response->assertSee("C:{$command->id}:SET OPTION DateTime=2026-04-17 18:00:00", false);
    }

    public function test_time_sync_queues_timezone_before_datetime_for_device_polling(): void
    {
        config()->set('app.timezone', 'UTC');
        config()->set('zkteco.system_timezone', 'Asia/Dhaka');
        Carbon::setTestNow(Carbon::parse('2026-04-17 12:00:00', 'UTC'));
        config()->set('zkteco.auto_pull_employees_on_contact', false);

        $device = Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'TEST123',
            'serial_number' => 'TEST123',
            'status' => 'active',
        ]);

        [$timezoneCommand, $timeCommand] = app(DeviceService::class)->queueTimeSync($device->id);

        $first = $this->get('/iclock/getrequest?SN=TEST123');
        $second = $this->get('/iclock/getrequest?SN=TEST123');

        $first->assertOk();
        $first->assertSee("C:{$timezoneCommand->id}:SET OPTION TimeZone=6", false);
        $second->assertOk();
        $second->assertSee("C:{$timeCommand->id}:SET OPTION DateTime=2026-04-17 18:00:00", false);
    }

    public function test_missing_serial_number_is_rejected_and_logged(): void
    {
        $response = $this->call(
            'POST',
            '/iclock/cdata',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            "1\t2026-04-17 09:00:00\t0",
        );

        $response->assertStatus(400);
        $response->assertSee('ERROR=Missing device serial number', false);

        $this->assertDatabaseHas('raw_logs', [
            'endpoint' => '/iclock/cdata',
            'processing_status' => 'failed',
        ]);
        $this->assertDatabaseHas('error_logs', [
            'type' => 'api_error',
            'message' => 'Missing device serial number',
        ]);
    }

    public function test_invalid_payload_format_is_rejected(): void
    {
        Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'TEST123',
            'serial_number' => 'TEST123',
            'status' => 'active',
        ]);

        $response = $this->call(
            'POST',
            '/iclock/cdata?SN=TEST123',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            'garbage payload without tabs or key values',
        );

        $response->assertStatus(400);
        $response->assertSee('ERROR=Malformed or unsupported device payload', false);
        $this->assertDatabaseCount('attendance_logs', 0);
        $this->assertDatabaseHas('error_logs', [
            'type' => 'api_error',
        ]);
    }

    public function test_empty_request_body_is_treated_as_option_handshake(): void
    {
        $response = $this->call(
            'POST',
            '/iclock/cdata?SN=TEST123',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            '',
        );

        $response->assertOk();
        $response->assertSee('GET OPTION FROM: TEST123', false);

        $this->assertDatabaseHas('devices', [
            'serial_number' => 'TEST123',
        ]);
    }

    public function test_unknown_device_is_rejected_when_auto_registration_is_disabled(): void
    {
        config()->set('zkteco.auto_register_devices', false);

        $response = $this->get('/iclock/getrequest?SN=UNKNOWN999');

        $response->assertStatus(403);
        $response->assertSee('ERROR=Unauthorized', false);
        $this->assertDatabaseMissing('devices', [
            'serial_number' => 'UNKNOWN999',
        ]);
    }

    public function test_deleted_device_serial_is_blocked_from_auto_registration(): void
    {
        \Illuminate\Support\Facades\DB::table('deleted_device_serials')->insert([
            'serial_number' => 'DELETED123',
            'device_name' => 'Deleted Device',
            'deleted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get('/iclock/getrequest?SN=DELETED123');

        $response->assertStatus(403);
        $response->assertSee('ERROR=Unauthorized', false);

        $this->assertDatabaseMissing('devices', [
            'serial_number' => 'DELETED123',
        ]);
    }

    public function test_require_token_rejects_unauthorized_requests(): void
    {
        config()->set('zkteco.require_token', true);

        Device::query()->create([
            'name' => 'Secure Device',
            'sn' => 'TEST123',
            'serial_number' => 'TEST123',
            'status' => 'active',
            'api_token' => 'expected-token',
        ]);

        $unauthorized = $this->get('/iclock/getrequest?SN=TEST123');
        $unauthorized->assertStatus(403);
        $unauthorized->assertSee('ERROR=Unauthorized', false);

        $authorized = $this->get('/iclock/getrequest?SN=TEST123&token=expected-token');
        $authorized->assertOk();
    }

    public function test_serial_allowlist_blocks_spoofed_serial_numbers(): void
    {
        config()->set('zkteco.allowed_serial_numbers', ['TEST123']);

        $blocked = $this->get('/iclock/getrequest?SN=SPOOF999');
        $blocked->assertStatus(403);
        $blocked->assertSee('ERROR=Unauthorized', false);

        $allowed = $this->get('/iclock/getrequest?SN=TEST123');
        $allowed->assertOk();
    }

    public function test_raw_requests_and_errors_are_logged_gracefully(): void
    {
        config()->set('zkteco.require_token', true);

        Device::query()->create([
            'name' => 'Secure Device',
            'sn' => 'TEST123',
            'serial_number' => 'TEST123',
            'status' => 'active',
            'api_token' => 'expected-token',
        ]);

        $this->get('/iclock/getrequest?SN=TEST123');

        $this->assertDatabaseHas('raw_logs', [
            'endpoint' => '/iclock/getrequest',
            'processing_status' => 'failed',
        ]);
        $this->assertDatabaseHas('error_logs', [
            'type' => 'api_error',
            'severity' => 'high',
        ]);
    }

    public function test_bulk_upload_of_one_thousand_logs_completes_and_persists_all_rows(): void
    {
        Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'TEST123',
            'serial_number' => 'TEST123',
            'status' => 'active',
        ]);

        $payload = collect(range(1, 1000))
            ->map(static function (int $index): string {
                $timestamp = sprintf('2026-04-17 09:%02d:%02d', intdiv($index - 1, 60), ($index - 1) % 60);
                $status = $index % 2;

                return "{$index}\t{$timestamp}\t{$status}";
            })
            ->implode("\r\n");

        $startedAt = microtime(true);

        $response = $this->call(
            'POST',
            '/iclock/cdata?SN=TEST123&table=ATTLOG&Stamp=1000',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            $payload,
        );

        $durationSeconds = microtime(true) - $startedAt;

        $response->assertOk();
        $response->assertSee('OK:1000', false);

        $this->assertDatabaseCount('attendance_logs', 1000);
        $this->assertLessThan(10, $durationSeconds, sprintf('Bulk upload took %.3f seconds', $durationSeconds));
    }

    public function test_retried_command_poll_after_sent_status_returns_ok_without_duplicate_delivery(): void
    {
        config()->set('zkteco.auto_pull_employees_on_contact', false);

        $device = Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'TEST123',
            'serial_number' => 'TEST123',
            'status' => 'active',
        ]);

        DeviceCommand::query()->create([
            'device_id' => $device->id,
            'command' => 'RESTART',
            'status' => 'pending',
        ]);

        $first = $this->get('/iclock/getrequest?SN=TEST123');
        $retry = $this->get('/iclock/getrequest?SN=TEST123');

        $first->assertOk();
        $first->assertSee('REBOOT', false);
        $retry->assertOk();
        $retry->assertSee('OK', false);

        $this->assertSame(1, DeviceCommand::query()->where('status', 'sent')->count());
    }

    public function test_stale_sent_commands_are_retried_after_the_retry_window(): void
    {
        config()->set('zkteco.command_retry_after_seconds', 1);
        config()->set('zkteco.auto_pull_employees_on_contact', false);

        $device = Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'TEST123',
            'serial_number' => 'TEST123',
            'status' => 'active',
        ]);

        $command = DeviceCommand::query()->create([
            'device_id' => $device->id,
            'command' => 'RESTART',
            'status' => DeviceCommand::STATUS_SENT,
            'sent_at' => now()->subSeconds(5),
            'retry_count' => 0,
        ]);

        $response = $this->get('/iclock/getrequest?SN=TEST123');

        $response->assertOk();
        $response->assertSee("C:{$command->id}:REBOOT", false);
        $this->assertDatabaseHas('device_commands', [
            'id' => $command->id,
            'status' => DeviceCommand::STATUS_SENT,
            'retry_count' => 1,
        ]);
    }

    public function test_devicecmd_without_matching_command_is_graceful_and_logged(): void
    {
        Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'TEST123',
            'serial_number' => 'TEST123',
            'status' => 'active',
        ]);

        $response = $this->call(
            'POST',
            '/iclock/devicecmd?SN=TEST123',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            "ID=999\nReturn=OK",
        );

        $response->assertOk();
        $response->assertSee('OK', false);

        $this->assertDatabaseHas('raw_logs', [
            'endpoint' => '/iclock/devicecmd',
            'processing_status' => 'processed',
            'record_count' => 0,
        ]);
    }

    public function test_empty_get_devicecmd_is_treated_as_a_noop_ping(): void
    {
        Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'TEST123',
            'serial_number' => 'TEST123',
            'status' => 'active',
        ]);

        $response = $this->get('/iclock/devicecmd?SN=TEST123');

        $response->assertOk();
        $response->assertSee('OK', false);

        $this->assertDatabaseHas('raw_logs', [
            'endpoint' => '/iclock/devicecmd',
            'processing_status' => 'processed',
            'record_count' => 0,
        ]);
    }

    public function test_devicecmd_accepts_query_string_callback_payloads(): void
    {
        $device = Device::query()->create([
            'name' => 'QA Device',
            'sn' => 'TEST123',
            'serial_number' => 'TEST123',
            'status' => 'active',
        ]);

        $command = DeviceCommand::query()->create([
            'device_id' => $device->id,
            'command' => 'RESTART',
            'status' => DeviceCommand::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $response = $this->get("/iclock/devicecmd?SN=TEST123&ID={$command->id}&Return=OK&CMD=REBOOT");

        $response->assertOk();
        $response->assertSee('OK', false);
        $this->assertDatabaseHas('device_commands', [
            'id' => $command->id,
            'status' => DeviceCommand::STATUS_DONE,
            'response' => 'REBOOT',
        ]);
    }
}
