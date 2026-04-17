@extends('admin.layout')

@section('title', 'Attendance Logs')
@section('page_title', 'Attendance Management')

@section('content')
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Device</label>
                <select name="device_id" class="form-select">
                    <option value="">All Devices</option>
                    @foreach($devices as $dev)
                    <option value="{{ $dev->id }}" {{ ($filters['device_id'] ?? '') === $dev->id ? 'selected' : '' }}>
                        {{ $dev->name }}
                    </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Date From</label>
                <input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}">
            </div>
            <div class="col-md-4">
                <label class="form-label">Date To</label>
                <input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}">
            </div>
            <div class="col-md-4">
                <label class="form-label">Employee</label>
                <select name="employee_id" class="form-select">
                    <option value="">All Employees</option>
                    @foreach($employees as $emp)
                    <option value="{{ $emp->id }}" {{ ($filters['employee_id'] ?? '') === $emp->id ? 'selected' : '' }}>
                        {{ $emp->full_name }}
                    </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Log Type</label>
                <select name="log_type" class="form-select">
                    <option value="">All Types</option>
                    <option value="checkin" {{ ($filters['log_type'] ?? '') === 'checkin' ? 'selected' : '' }}>Check In</option>
                    <option value="checkout" {{ ($filters['log_type'] ?? '') === 'checkout' ? 'selected' : '' }}>Check Out</option>
                    <option value="break" {{ ($filters['log_type'] ?? '') === 'break' ? 'selected' : '' }}>Break</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All Status</option>
                    <option value="processed" {{ ($filters['status'] ?? '') === 'processed' ? 'selected' : '' }}>Processed</option>
                    <option value="pending" {{ ($filters['status'] ?? '') === 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="error" {{ ($filters['status'] ?? '') === 'error' ? 'selected' : '' }}>Error</option>
                </select>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary">Filter</button>
                <a href="{{ route('admin.attendance.export', $filters) }}" class="btn btn-success">
                    <i class="bi bi-download"></i> Export CSV
                </a>
                <a href="{{ route('admin.attendance.index') }}" class="btn btn-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <table class="table table-hover mb-0">
        <thead class="table-light">
            <tr>
                <th>Device</th>
                <th>Employee</th>
                <th>Time</th>
                <th>Type</th>
                <th>Verify</th>
                <th>Status</th>
                <th>Actions</th>
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
                <td><small>{{ ucfirst($log->verify_type) }}</small></td>
                <td>
                    <span class="badge bg-{{ $log->status === 'processed' ? 'success' : ($log->status === 'error' ? 'danger' : 'warning') }}">
                        {{ ucfirst($log->status) }}
                    </span>
                </td>
                <td>
                    @if($log->status === 'error')
                    <small class="text-danger">{{ $log->error_message }}</small>
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="7" class="text-center text-muted">No logs found</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<nav class="mt-3">
    {{ $logs->links('pagination::bootstrap-5') }}
</nav>
@endsection
