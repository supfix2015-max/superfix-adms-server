<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZktecoAdmsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_registers_a_device_during_handshake_and_returns_options(): void
    {
        $response = $this->get('/iclock/cdata?SN=ACME001&options=all');

        $response->assertOk();
        $response->assertSee('GET OPTION FROM: ACME001', false);

        $device = Device::query()->firstOrFail();

        $this->assertSame('ACME001', $device->serial_number);
        $this->assertDatabaseHas('raw_logs', [
            'device_id' => $device->id,
            'endpoint' => '/iclock/cdata',
            'processing_status' => 'processed',
        ]);
    }

    public function test_it_stores_multiple_attendance_logs_from_a_single_tab_delimited_payload(): void
    {
        Http::fake([
            'https://webhook.example/*' => Http::response(['ok' => true], 200),
        ]);

        config()->set('zkteco.webhook.url', 'https://webhook.example/attendance');

        Device::query()->create([
            'name' => 'Main Gate',
            'sn' => 'ACME001',
            'serial_number' => 'ACME001',
            'status' => 'active',
            'api_token' => 'secret-token',
        ]);

        $payload = implode("\r\n", [
            "1001\t2026-04-17 08:30:00\t0\t1\t0\t\t\t1\tATTLOG",
            "1002\t2026-04-17 08:31:00\t1\t15\t0\t\t\t2\tATTLOG",
        ]);

        $response = $this->call(
            'POST',
            '/iclock/cdata?SN=ACME001&table=ATTLOG&Stamp=1',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            $payload,
        );

        $response->assertOk();
        $response->assertSee('OK:2', false);

        $this->assertDatabaseCount('attendance_logs', 2);
        $this->assertDatabaseHas('employees', ['employee_id' => '1001']);
        $this->assertDatabaseHas('attendance_logs', [
            'employee_code' => '1002',
            'verify_type' => 'face',
            'status' => 'processed',
        ]);

        Http::assertSentCount(1);
    }

    public function test_it_returns_the_oldest_pending_command_in_zkteco_format(): void
    {
        $device = Device::query()->create([
            'name' => 'Main Gate',
            'sn' => 'ACME001',
            'serial_number' => 'ACME001',
            'status' => 'active',
            'api_token' => 'secret-token',
        ]);

        $command = DeviceCommand::query()->create([
            'device_id' => $device->id,
            'command' => 'RESTART',
            'status' => 'pending',
        ]);

        $response = $this->get('/iclock/getrequest?SN=ACME001');

        $response->assertOk();
        $response->assertSee("C:{$command->id}:REBOOT", false);

        $this->assertDatabaseHas('device_commands', [
            'id' => $command->id,
            'status' => 'sent',
        ]);
    }

    public function test_it_marks_a_device_command_as_executed_when_the_device_posts_back_results(): void
    {
        $device = Device::query()->create([
            'name' => 'Main Gate',
            'sn' => 'ACME001',
            'serial_number' => 'ACME001',
            'status' => 'active',
            'api_token' => 'secret-token',
        ]);

        $command = DeviceCommand::query()->create([
            'device_id' => $device->id,
            'command' => 'RESTART',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $response = $this->call(
            'POST',
            '/iclock/devicecmd?SN=ACME001',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            "ID={$command->id}&Return=0&CMD=REBOOT",
        );

        $response->assertOk();

        $this->assertDatabaseHas('device_commands', [
            'id' => $command->id,
            'status' => 'done',
            'response' => 'REBOOT',
        ]);
    }
}
