@extends('admin.layout')

@section('title', 'Employees')
@section('page_title', 'Employee Directory')
@section('page_subtitle', 'Manage employee identities, sync status, credentials, and biometric replay from a single directory.')

@section('page_actions')
    @if (config('legacy_attendance.browser_enabled', false))
        <a href="{{ route('admin.legacy.index', ['tab' => 'employees']) }}" class="btn btn-outline-primary">
            <i class="bi bi-database me-1"></i> Original SQL Server Employees
        </a>
    @endif
    <a href="{{ route('admin.employees.create') }}" class="btn btn-success">
        <i class="bi bi-plus-lg me-1"></i>
        Add Employee
    </a>
@endsection

@section('content')
    <div class="card">
        <div class="card-body">
            <div class="page-toolbar">
                <div>
                    <h5 class="mb-1">Search And Filter</h5>
                    <p class="small mb-0">Refine the directory by employee identifier, status, or operational segment.</p>
                </div>
                <div class="toolbar-cluster">
                    <button type="button" class="btn btn-success" onclick="syncEmployees()">
                        <i class="bi bi-arrow-repeat me-1"></i> Sync Employees
                    </button>
                    <button type="button" class="btn btn-outline-success" onclick="syncFaceTemplates()">
                        <i class="bi bi-person-bounding-box me-1"></i> Sync Face Templates
                    </button>
                    <button type="button" class="btn btn-outline-success" onclick="syncFingerTemplates()">
                        <i class="bi bi-fingerprint me-1"></i> Sync Finger Templates
                    </button>
                </div>
            </div>

            <form method="GET" class="row g-3 mt-2">
                <div class="col-lg-6">
                    <input type="text" name="search" class="form-control" placeholder="Search employee ID, name, email, or card..." value="{{ $filters['search'] ?? '' }}">
                </div>
                <div class="col-sm-6 col-lg-3">
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        @foreach(\App\Models\Employee::statusOptions() as $status)
                            <option value="{{ $status }}" {{ ($filters['status'] ?? '') === $status ? 'selected' : '' }}>
                                {{ \App\Models\Employee::labelForStatus($status) }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="toolbar-cluster">
                        <button type="submit" class="btn btn-primary">Apply Filters</button>
                        @if (request()->hasAny(['search', 'status']))
                            <a href="{{ route('admin.employees.index') }}" class="btn btn-outline-secondary">Reset</a>
                        @endif
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card table-shell">
        <div class="card-header">
            <div>
                <h5 class="shell-title">Employee Records</h5>
                <div class="shell-subtitle">{{ $employees->total() }} employees matched the current directory filters.</div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Department</th>
                            <th>Status</th>
                            <th>Hire Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($employees as $employee)
                            <tr>
                                <td><code>{{ $employee->employee_id ?? 'ARCHIVED' }}</code></td>
                                <td>
                                    <div class="fw-semibold">{{ $employee->sync_name ?: $employee->full_name }}</div>
                                    <div class="small">{{ $employee->phone ?? 'No phone number' }}</div>
                                </td>
                                <td>{{ $employee->email ?? '-' }}</td>
                                <td>{{ $employee->department ?? '-' }}</td>
                                <td>
                                    <span class="badge bg-{{ $employee->status_badge_class }}">
                                        {{ $employee->status_label }}
                                    </span>
                                </td>
                                <td>{{ $employee->hire_date ? \Carbon\Carbon::parse($employee->hire_date)->format('Y-m-d') : '-' }}</td>
                                <td>
                                    <div class="toolbar-cluster">
                                        <a href="{{ route('admin.employees.show', $employee) }}" class="btn btn-sm btn-outline-primary">View</a>
                                        <a href="{{ route('admin.employees.edit', $employee) }}" class="btn btn-sm btn-outline-warning">Edit</a>
                                        <form method="POST" action="{{ route('admin.employees.destroy', $employee) }}" class="d-inline" onsubmit="return confirm('Offboard this employee and remove them from all devices?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Offboard</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <div class="empty-state">
                                        <i class="bi bi-people"></i>
                                        <div class="fw-semibold">No employees found</div>
                                        <div class="small">Create an employee or relax the directory filters to see records here.</div>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer">
            {{ $employees->links('pagination::bootstrap-5') }}
        </div>
    </div>

    @push('extra_js')
        <script>
            function queueSync(scope, confirmationText) {
                if (!confirm(confirmationText)) return;

                fetch('{{ route('admin.user-syncs.store') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        body: JSON.stringify({
                            force: false,
                            scope: scope
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.error) {
                            alert('Error: ' + data.message);
                        } else {
                            alert(data.message);
                        }
                    })
                    .catch(() => {
                        alert('Sync failed. Please review the browser console or server logs.');
                    });
            }

            function syncEmployees() {
                queueSync('employees', 'Queue employee sync for all active devices?');
            }

            function syncFaceTemplates() {
                queueSync('face', 'Queue face template sync for every employee who already has a face template?');
            }

            function syncFingerTemplates() {
                queueSync('fingerprint', 'Queue fingerprint template sync for every employee who already has a fingerprint template?');
            }
        </script>
    @endpush
@endsection
