<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Admin user
        User::create([
            'name' => 'Administrator',
            'email' => 'admin@adms.local',
            'password' => bcrypt('admin123'),
            'role' => 'admin',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        // Operator user
        User::create([
            'name' => 'Operator',
            'email' => 'operator@adms.local',
            'password' => bcrypt('operator123'),
            'role' => 'operator',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        // Viewer user
        User::create([
            'name' => 'Viewer',
            'email' => 'viewer@adms.local',
            'password' => bcrypt('viewer123'),
            'role' => 'viewer',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }
}