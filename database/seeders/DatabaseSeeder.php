<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(UserSeeder::class);
        if (app()->environment('local') && filter_var(env('ADMS_SEED_DEMO_DATA', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->call([EmployeeSeeder::class, DeviceSeeder::class]);
        }
    }
}
