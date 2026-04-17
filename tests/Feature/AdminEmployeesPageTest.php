<?php

namespace Tests\Feature;

use App\Models\Device;
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
}
