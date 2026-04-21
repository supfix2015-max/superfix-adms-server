@extends('admin.layout')

@section('title', 'Employees')
@section('page_title', 'Employee Management')

@section('content')
    <div class="row mb-3">
        <div class="col-md-6">
            <form method="GET" class="d-flex gap-2">
                <input type="text" name="search" class="form-control" placeholder="Search employees..."
                    value="{{ $filters['search'] ?? '' }}">
                <select name="status" class="form-select" style="max-width: 150px">
                    <option value="">All Status</option>
                    <option value="active" {{ ($filters['status'] ?? '') === 'active' ? 'selected' : '' }}>Active</option>
                    <option value="inactive" {{ ($filters['status'] ?? '') === 'inactive' ? 'selected' : '' }}>Inactive
                    </option>
                    <option value="suspended" {{ ($filters['status'] ?? '') === 'suspended' ? 'selected' : '' }}>Suspended
                    </option>
                </select>
                <button type="submit" class="btn btn-primary">Filter</button>
            </form>
        </div>
        <div class="col-md-6 text-end">
            <a href="{{ route('admin.employees.create') }}" class="btn btn-success">
                <i class="bi bi-plus"></i> Add Employee
            </a>
            <button type="button" class="btn btn-success" onclick="syncEmployees()">
                <i class="bi bi-arrow-repeat"></i> Sync Employees
            </button>
            <button type="button" class="btn btn-success" onclick="syncFaceTemplates()">
                <i class="bi bi-arrow-repeat"></i> Sync Face Templates
            </button>
            <button type="button" class="btn btn-success" onclick="syncFingerTemplates()">
                <i class="bi bi-arrow-repeat"></i> Sync Finger Templates
            </button>
        </div>
    </div>

    <div class="card">
        <table class="table table-hover mb-0">
            <thead class="table-light">
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
                        <td><code>{{ $employee->employee_id }}</code></td>
                        <td>{{ $employee->full_name }}</td>
                        <td>{{ $employee->email ?? '-' }}</td>
                        <td>{{ $employee->department ?? '-' }}</td>
                        <td>
                            <span
                                class="badge bg-{{ $employee->status === 'active' ? 'success' : ($employee->status === 'inactive' ? 'secondary' : 'danger') }}">
                                {{ ucfirst($employee->status) }}
                            </span>
                        </td>
                        <td>{{ $employee->hire_date ? \Carbon\Carbon::parse($employee->hire_date)->format('Y-m-d') : '-' }}
                        </td>
                        <td>
                            <a href="{{ route('admin.employees.show', $employee) }}" class="btn btn-sm btn-info">View</a>
                            <a href="{{ route('admin.employees.edit', $employee) }}"
                                class="btn btn-sm btn-warning">Edit</a>
                            <form method="POST" action="{{ route('admin.employees.destroy', $employee) }}"
                                style="display: inline;">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-danger"
                                    onclick="return confirm('Are you sure?')">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted">No employees found</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <nav class="mt-3">
        {{ $employees->links('pagination::bootstrap-5') }}
    </nav>

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
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Sync failed. Check console.');
                    });
            }

            function syncEmployees() {
                queueSync('employees', 'Queue employee sync for all active devices?');
            }

            function syncFaceTemplates() {
                queueSync('face', 'Queue face template sync for every employee who has a face template?');
            }

            function syncFingerTemplates() {
                queueSync('fingerprint', 'Queue fingerprint template sync for every employee who has a fingerprint template?');
            }
        </script>
    @endpush
@endsection
