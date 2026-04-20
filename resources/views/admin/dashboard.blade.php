@extends('admin.layout')

@section('title', 'Dashboard')
@section('page_title', 'Dashboard')

@section('content')
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <h6 class="card-title text-muted">Total Devices</h6>
                <h3 class="mb-0">{{ $deviceStats['total'] }}</h3>
                <small class="text-success"><i class="bi bi-arrow-up"></i> Active: {{ $deviceStats['active'] }}</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <h6 class="card-title text-muted">Online Devices</h6>
                <h3 class="mb-0 text-success">{{ $deviceStats['online'] }}</h3>
                <small class="text-danger">Offline: {{ $deviceStats['offline'] }}</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <h6 class="card-title text-muted">Total Employees</h6>
                <h3 class="mb-0">{{ $employeeStats['total'] }}</h3>
                <small class="text-success">Active: {{ $employeeStats['active'] }}</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <h6 class="card-title text-muted">Today's Logs</h6>
                <h3 class="mb-0">{{ $attendanceStats['processed'] ?? 0 }}</h3>
                <small class="text-warning">Pending: {{ $attendanceStats['pending'] ?? 0 }}</small>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Recent Devices</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Status</th>
                            <th>Last Heartbeat</th>
                            <th>Pending Command</th>
                            <th>Command</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentDevices as $device)
                        <tr>
                            <td>
                                <a href="{{ route('admin.devices.show', $device) }}">{{ $device->name }}</a>
                            </td>
                            <td>
                                <span class="badge badge-{{ $device->isOnline() ? 'online' : 'offline' }}">
                                    {{ ucfirst($device->status) }}
                                </span>
                            </td>
                            <td>{{ $device->last_heartbeat?->diffForHumans() }}</td>
                            <td>
                                @if($device->pending_command)
                                <span class="badge bg-warning">Pending: {{ $device->pending_command }}</span>
                                @else
                                <span class="text-muted">No pending commands</span>
                                @endif
                            </td>
                            <td>
                                <form action="{{ route('admin.devices.command', $device) }}" method="POST" class="d-flex gap-1">
                                    @csrf
                                    <select name="command" class="form-select form-select-sm" required>
                                        <option value="">Select Command</option>
                                        <option value="REBOOT">Reboot</option>
                                        <option value="UPDATE_TIME">Sync Time</option>
                                        <option value="ClearAdmin">Clear Admin</option>
                                        <option value="ENABLE_ATTENDANCE">Enable Realtime</option>
                                        <option value="CLEAR_LOGS">Clear Logs</option>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-primary">Send</button>
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted">No devices</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Recent Attendance Logs</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Device</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentLogs as $log)
                        <tr>
                            <td>{{ $log->employee?->full_name ?? $log->employee_code ?? 'Unknown' }}</td>
                            <td>{{ $log->device?->name ?? 'Unknown Device' }}</td>
                            <td>{{ $log->log_time->format('H:i:s') }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="3" class="text-center text-muted">No logs</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@if(isset($unmatched) && $unmatched > 0)
<div class="alert alert-warning mt-4">
    <i class="bi bi-exclamation-triangle"></i>
    <strong>Attention!</strong> There are {{ $unmatched }} unmatched attendance logs.
    <a href="{{ route('admin.logs.index') }}" class="alert-link">Review now</a>
</div>
@endif
@endsection
