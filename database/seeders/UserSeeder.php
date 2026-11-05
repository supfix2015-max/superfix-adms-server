<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('INITIAL_ADMIN_EMAIL');
        $password = env('INITIAL_ADMIN_PASSWORD');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! is_string($password) || strlen($password) < 20) {
            $this->command?->warn('No admin created: configure a valid INITIAL_ADMIN_EMAIL and INITIAL_ADMIN_PASSWORD (20+ chars).');
            return;
        }

        User::firstOrCreate(['email' => $email], [
            'name' => 'Superfix ADMS Administrator',
            'password' => Hash::make($password),
            'role' => 'admin',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }
}
