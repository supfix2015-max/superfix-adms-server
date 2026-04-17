<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Repositories\EmployeeRepository;
use App\Services\EmployeeService;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function __construct(
        protected EmployeeService $employeeService,
        protected EmployeeRepository $employeeRepository
    ) {}

    public function index(Request $request)
    {
        $filters = [
            'search' => $request->input('search'),
            'status' => $request->input('status'),
            'department' => $request->input('department'),
        ];

        $employees = $this->employeeRepository->paginateWithFilters($filters, 20);
        $departments = $this->employeeRepository->getDepartments();

        return view('admin.employees.index', [
            'employees' => $employees,
            'filters' => $filters,
            'departments' => $departments,
        ]);
    }

    public function create()
    {
        $departments = $this->employeeRepository->getDepartments();

        return view('admin.employees.create', [
            'departments' => $departments,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|string|unique:employees',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:employees',
            'phone' => 'nullable|string',
            'department' => 'nullable|string',
            'hire_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        try {
            $employee = $this->employeeService->createEmployee($validated);

            return redirect()->route('admin.employees.show', $employee)
                ->with('success', 'Employee created successfully');
        } catch (\Exception $e) {
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function show(Employee $employee)
    {
        $employee->load('attendances');

        return view('admin.employees.show', [
            'employee' => $employee,
        ]);
    }

    public function edit(Employee $employee)
    {
        $departments = $this->employeeRepository->getDepartments();

        return view('admin.employees.edit', [
            'employee' => $employee,
            'departments' => $departments,
        ]);
    }

    public function update(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'department' => 'nullable|string',
            'status' => 'in:active,inactive,suspended',
            'hire_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $this->employeeService->updateEmployee($employee->id, $validated);

        return redirect()->route('admin.employees.show', $employee)
            ->with('success', 'Employee updated successfully');
    }

    public function destroy(Employee $employee)
    {
        $this->employeeService->deleteEmployee($employee->id);

        return redirect()->route('admin.employees.index')
            ->with('success', 'Employee deleted successfully');
    }
}
