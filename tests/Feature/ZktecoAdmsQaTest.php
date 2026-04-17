<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Services\DeviceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_operlog_user_payload_variant_is_parsed_and_persisted_as_employees(): void
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
        $response->assertSee('OK:2', false);

        $this->assertDatabaseHas('employees', [
            'employee_id' => '1',
            'name' => 'User 1',
        ]);
        $this->assertDatabaseHas('employees', [
            'employee_id' => '1001',
            'name' => 'User 1001',
            'card_no' => '1234',
        ]);
        $this->assertDatabaseHas('raw_logs', [
            'endpoint' => '/iclock/cdata',
            'processing_status' => 'processed',
            'record_count' => 2,
        ]);
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
