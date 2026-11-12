<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacySqlServerBrowserTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_visitors_cannot_view_original_sql_server_tables(): void
    {
        config()->set('legacy_attendance.browser_enabled', true);

        $this->get('/admin/legacy')->assertRedirect('/login');
    }

    public function test_browser_is_disabled_by_default_even_for_admins(): void
    {
        config()->set('legacy_attendance.browser_enabled', false);

        $admin = User::query()->create([
            'name' => 'Read Only Test Admin',
            'email' => 'legacy-admin@example.test',
            'role' => 'admin',
            'password' => 'password',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.legacy.index'))
            ->assertNotFound();
    }
}
