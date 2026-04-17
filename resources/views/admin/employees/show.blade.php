@extends('admin.layout')

@section('title', "Employee: {$employee->full_name}")
@section('page_title', $employee->full_name)
@section('content')
<div class="row g-4">
    {{-- Employee Details --}}
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Employee Details</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <strong>Employee ID:</strong><br>
                        <code>{{ $employee->employee_id }}</code>
                    </div>
                    <div class="col-md-6">
                        <strong>Status:</strong><br>
                        <span class="badge bg-{{ $employee->status === 'active' ? 'success' : ($employee->status === 'inactive' ? 'secondary' : 'warning') }}">
                            {{ ucfirst($employee->status) }}
                        </span>
                    </div>
                    <div class="col-md-6">
                        <strong>Full Name:</strong><br>
                        {{ $employee->full_name }}
                    </div>
                    <div class="col-md-6">
                        <strong>Email:</strong><br>
                        {{ $employee->email ?? 'Not set' }}
                    </div>
                    <div class="col-md-6">
                        <strong>Phone:</strong><br>
                        {{ $employee->phone ?? 'Not set' }}
                    </div>
                    <div class="col-md-6">
                        <strong>Department:</strong><br>
                        <span class="badge bg-light text-dark px-3 py-2">{{ $employee->department ?? 'Not assigned' }}</span>
                    </div>
                    <div class="col-md-6">
                        <strong>Hire Date:</strong><br>
                        {{ $employee->hire_date ? \Carbon\Carbon::parse($employee->hire_date)->format('M d, Y') : 'Not set' }}
                    </div>
                    <div class="col-12">
                        <strong>Notes:</strong><br>
                        <div class="bg-light p-3 rounded">{{ $employee->notes ?? 'No notes' }}</div>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-top">
                    <div class="d-flex gap-2">
                        <a href="{{ route('admin.employees.edit', $employee) }}" class="btn btn-warning">
                            <i class="bi bi-pencil"></i> Edit Employee
                        </a>
                        <a href="{{ route('admin.employees.index') }}" class="btn btn-secondary">Back to List</a>
                        <form method="POST" action="{{ route('admin.employees.destroy', $employee) }}" class="d-inline" 
                              onsubmit="return confirm('Delete {{ $employee->full_name }}? All records will be preserved.');">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-danger">Delete Employee</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Quick Stats --}}
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="card-title mb-0">Attendance Summary</h6>
            </div>
            <div class="card-body">
                <div class="row text-center g-3 mb-3">
                    <div class="col-6 border-end">
                        <div class="h6 mb-0">{{ $employee->attendanceLogs()->whereMonth('created_at', now()->month)->count() }}</div>
                        <small class="text-muted">This Month</small>
                    </div>
                    <div class="col-6">
                        <div class="h6 mb-0">{{ $employee->attendanceLogs()->whereYear('created_at', now()->year)->count() }}</div>
                        <small class="text-muted">This Year</small>
                    </div>
                </div>

                <h6 class="mb-2">Recent Activity</h6>
                @forelse($employee->attendanceLogs()->latest()->take(5)->get() as $log)
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom small">
                        <span>{{ $log->device->name ?? 'Unknown' }}</span>
                        <span class="text-end">
                            <span class="badge bg-{{ $log->log_type === 'checkin' ? 'success' : 'info' }} me-1">{{ ucfirst($log->log_type) }}</span>
                            <small class="text-muted">{{ $log->log_time?->format('M j, H:i') ?? $log->created_at->format('M j, H:i') }}</small>
                        </span>
                    </div>
                @empty
                    <div class="text-muted text-center py-3">No attendance records</div>
                @endforelse
            </div>
        </div>
    </div>
</div>

@endsection

