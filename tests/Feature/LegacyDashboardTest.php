<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LegacyDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class LegacyDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_rejects_unauthenticated_users_in_sql_mode(): void
    {
        config()->set('legacy_attendance.browser_enabled', true);

        $this->get('/admin/dashboard')->assertRedirect('/login');
    }

    public function test_dashboard_renders_original_sql_source_instead_of_staged_mysql_counters(): void
    {
        config()->set('legacy_attendance.browser_enabled', true);

        $admin = User::query()->create([
            'name' => 'Dashboard Test Admin',
            'email' => 'dashboard-admin@example.test',
            'role' => 'admin',
            'password' => 'password',
        ]);

        $service = Mockery::mock(LegacyDashboardService::class);
        $service->shouldReceive('overview')->once()->andReturn([
            'totals' => [
                'employees' => 334,
                'attendance' => 584196,
                'devices' => 22,
                'templates' => 771,
            ],
            'latestAt' => \Carbon\CarbonImmutable::parse('2026-11-04 08:00:25'),
            'daily' => [
                'total' => 20,
                'checkins' => 12,
                'checkouts' => 8,
                'unique_employees' => 10,
            ],
            'trend' => [],
            'statusBreakdown' => [],
            'recentPunches' => collect(),
            'registeredDevices' => collect(),
            'lastDataIsStale' => true,
            'sourceTimeZone' => 'Asia/Dubai',
        ]);
        $this->app->instance(LegacyDashboardService::class, $service);

        $this->actingAs($admin)
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertViewIs('admin.dashboard_sql')
            ->assertSee('584,196')
            ->assertSee('Original SQL Server')
            ->assertSee('Attendance trend');
    }
}
