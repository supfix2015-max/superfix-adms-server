@extends('layouts.app')

@section('title', 'Map Employee IDs')

@section('content')
    @php($directory = $employees ?? null)

    <div class="card mb-4">
        <div class="card-body">
            <div class="page-toolbar">
                <div>
                    <span class="eyebrow text-primary fw-bold text-uppercase small">Legacy Mapping</span>
                    <h1 class="mt-2 mb-1">Map Employee IDs With Names</h1>
                    <p class="small mb-0">Providing the same employee ID replaces the existing mapped name for that record.</p>
                </div>
                @if ($directory)
                    <div class="stat-pill">
                        <span>Mapped Employees</span>
                        <strong>{{ method_exists($directory, 'total') ? $directory->total() : count($directory) }}</strong>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-4">
            <div class="card shell-card">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">Create Or Update Mapping</h5>
                        <div class="shell-subtitle">Use numeric employee IDs and human-readable names for legacy attendance matching.</div>
                    </div>
                </div>
                <div class="card-body">
                    <form action="{{ route('employee.store') }}" method="POST" class="panel-stack">
                        @csrf
                        <div>
                            <label for="employee_id" class="form-label fw-semibold">Employee ID</label>
                            <input type="number" class="form-control" id="employee_id" name="employee_id" placeholder="Enter Employee ID" required>
                        </div>
                        <div>
                            <label for="employee_name" class="form-label fw-semibold">Employee Name</label>
                            <input type="text" class="form-control" id="employee_name" name="employee_name" placeholder="Enter Employee Name" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Save Mapping</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-8">
            <div class="card shell-card mb-4">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">Search Directory</h5>
                        <div class="shell-subtitle">Filter mapped employees by name prefix when the paginated directory is available.</div>
                    </div>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-8">
                            <label for="name" class="form-label fw-semibold">Employee Name</label>
                            <input type="text" id="name" name="name" class="form-control" value="{{ request('name') }}" placeholder="Search by employee name prefix">
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="toolbar-cluster w-100">
                                <button type="submit" class="btn btn-primary w-100">Apply Filter</button>
                                @if (request()->filled('name'))
                                    <a href="{{ url()->current() }}" class="btn btn-outline-secondary w-100">Reset</a>
                                @endif
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card table-shell">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">Mapped Employees</h5>
                        <div class="shell-subtitle">Legacy name map used by the older attendance reporting flow.</div>
                    </div>
                </div>
                <div class="card-body p-0">
                    @if ($directory)
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Employee ID</th>
                                        <th>Employee Name</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($directory as $employee)
                                        <tr>
                                            <td><code>{{ $employee->employee_id }}</code></td>
                                            <td>{{ !empty($employee->name) ? $employee->name : 'N/A' }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="2">
                                                <div class="empty-state">
                                                    <i class="bi bi-person-lines-fill"></i>
                                                    <div class="fw-semibold">No mapped employees found</div>
                                                    <div class="small">Create a mapping to start labeling attendance rows with employee names.</div>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="empty-state">
                            <i class="bi bi-people"></i>
                            <div class="fw-semibold">Directory data is not loaded on this route</div>
                            <div class="small">Use the paginated employee mapping route to browse existing records here.</div>
                        </div>
                    @endif
                </div>
                @if ($directory && method_exists($directory, 'links'))
                    <div class="card-footer">
                        {{ $directory->links('pagination::bootstrap-5') }}
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
