<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Employee;

class EmployeeSeeder extends Seeder
{
    public function run(): void
    {
        $employees = [
            [
                'employee_id' => 'EMP001',
'name' => 'Ahmed Khan',
                'email' => 'ahmed.khan@company.com',
                'phone' => '+1234567890',
                'department' => 'Engineering',
                'hire_date' => now()->subMonths(12),
                'status' => 'active',
            ],
            [
                'employee_id' => 'EMP002',
                'name' => 'Fatima Ahmed',
                'email' => 'fatima.ahmed@company.com',
                'phone' => '+1234567891',
                'department' => 'Marketing',
                'hire_date' => now()->subMonths(6),
                'status' => 'active',
            ],
            [
                'employee_id' => 'EMP003',
                'name' => 'Mohammad Ali',
                'email' => 'mohammad.ali@company.com',
                'phone' => '+1234567892',
                'department' => 'Operations',
                'hire_date' => now()->subMonths(24),
                'status' => 'active',
            ],
            [
                'employee_id' => 'EMP004',
                'name' => 'Sara Hassan',
                'email' => 'sara.hassan@company.com',
                'phone' => '+1234567893',
                'department' => 'HR',
                'hire_date' => now()->subMonths(18),
                'status' => 'active',
            ],
            [
                'employee_id' => 'EMP005',
                'name' => 'Karim Ibrahim',
                'email' => 'karim.ibrahim@company.com',
                'phone' => '+1234567894',
                'department' => 'Engineering',
                'hire_date' => now()->subMonths(3),
                'status' => 'active',
            ],
        ];

        foreach ($employees as $employee) {
            Employee::create($employee);
        }
    }
}
