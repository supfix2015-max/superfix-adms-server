@extends('admin.layout')

@section('title', 'Attendance Logs')
@section('page_title', 'Attendance Ledger')
@section('page_subtitle', 'Review processed attendance, unmatched logs, and export-ready transaction history across devices and employees.')

@section('page_actions')
    @if (config('legacy_attendance.browser_enabled', false))
        <a href="{{ route('admin.legacy.index', ['tab' => 'attendance']) }}" class="btn btn-outline-primary">
            <i class="bi bi-database me-1"></i> Original SQL Server Attendance
        </a>
    @endif
    <a href="{{ route('admin.attendance.export', $filters) }}" class="btn btn-success">
        <i class="bi bi-download me-1"></i>
        Export CSV
    </a>
@endsection

@section('content')
    <div class="card">
        <div class="card-body">
            <div class="page-toolbar">
                <div>
                    <h5 class="mb-1">Ledger Filters</h5>
                    <p class="small mb-0">Slice attendance by device, employee, date range, verification type, and processing state.</p>
                </div>
            </div>

            <form method="GET" class="row g-3 mt-2">
                <div class="col-lg-4">
                    <label class="form-label">Device</label>
                    <select name="device_id" class="form-select">
                        <option value="">All Devices</option>
                        @foreach($devices as $dev)
                            <option value="{{ $dev->id }}" {{ (string) ($filters['device_id'] ?? '') === (string) $dev->id ? 'selected' : '' }}>
                                {{ $dev->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label">Date From</label>
                    <input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}">
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label">Date To</label>
                    <input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}">
                </div>
                <div class="col-lg-4">
                    <label class="form-label">Employee</label>
                    <select name="employee_id" class="form-select">
                        <option value="">All Employees</option>
                        @foreach($employees as $emp)
                            <option value="{{ $emp->id }}" {{ (string) ($filters['employee_id'] ?? '') === (string) $emp->id ? 'selected' : '' }}>
                                {{ $emp->full_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label">Log Type</label>
                    <select name="log_type" class="form-select">
                        <option value="">All Types</option>
                        <option value="checkin" {{ ($filters['log_type'] ?? '') === 'checkin' ? 'selected' : '' }}>Check In</option>
                        <option value="checkout" {{ ($filters['log_type'] ?? '') === 'checkout' ? 'selected' : '' }}>Check Out</option>
                        <option value="break" {{ ($filters['log_type'] ?? '') === 'break' ? 'selected' : '' }}>Break</option>
                    </select>
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="processed" {{ ($filters['status'] ?? '') === 'processed' ? 'selected' : '' }}>Processed</option>
                        <option value="pending" {{ ($filters['status'] ?? '') === 'pending' ? 'selected' : '' }}>Pending</option>
                        <option value="error" {{ ($filters['status'] ?? '') === 'error' ? 'selected' : '' }}>Error</option>
                    </select>
                </div>
                <div class="col-12">
                    <div class="toolbar-cluster">
                        <button type="submit" class="btn btn-primary">Apply Filters</button>
                        <a href="{{ route('admin.attendance.index') }}" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card table-shell">
        <div class="card-header">
            <div>
                <h5 class="shell-title">Attendance Transactions</h5>
                <div class="shell-subtitle">{{ $logs->total() }} records matched the selected attendance filters.</div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Device</th>
                            <th>Employee</th>
                            <th>Time</th>
                            <th>Type</th>
                            <th>Verify</th>
                            <th>Status</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($logs as $log)
                            <tr>
                                <td>{{ $log->device?->name ?? 'Unknown Device' }}</td>
                                <td>
                                    @if($log->employee && ! $log->employee->trashed())
                                        <a href="{{ route('admin.employees.show', $log->employee) }}">{{ $log->employee->full_name }}</a>
                                    @elseif($log->employee)
                                        <span>{{ $log->employee->full_name }} (deleted)</span>
                                    @else
                                        <span class="text-danger">{{ $log->employee_code ?? 'Unknown' }}</span>
                                    @endif
                                </td>
                                <td>{{ $log->log_time?->format('Y-m-d H:i:s') ?? '-' }}</td>
                                <td>{{ ucfirst($log->log_type) }}</td>
                                <td><span class="small">{{ ucfirst($log->verify_type) }}</span></td>
                                <td>
                                    <span class="badge bg-{{ $log->status === 'processed' ? 'success' : ($log->status === 'error' ? 'danger' : 'warning') }}">
                                        {{ ucfirst($log->status) }}
                                    </span>
                                </td>
                                <td>
                                    @if($log->status === 'error')
                                        <span class="small text-danger">{{ $log->error_message }}</span>
                                    @else
                                        <span class="small text-muted">OK</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <div class="empty-state">
                                        <i class="bi bi-calendar2-check"></i>
                                        <div class="fw-semibold">No attendance logs found</div>
                                        <div class="small">Try widening the date range or removing one of the active filters.</div>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer">
            {{ $logs->links('pagination::bootstrap-5') }}
        </div>
    </div>
@endsection
