@extends('admin.layout')

@section('title', 'Dashboard')
@section('page_title', 'Operational Dashboard')
@section('page_subtitle', 'Monitor device health, employee coverage, and today\'s attendance traffic from one control surface.')

@section('content')
    @if (config('legacy_attendance.browser_enabled', false))
        <div class="alert alert-info">
            <i class="bi bi-database me-1"></i>
            Original historical records remain on SQL Server and are available
            <a href="{{ route('admin.legacy.index') }}" class="alert-link">read-only in the original database browser</a>.
            This dashboard continues to reflect ADMS MySQL device uploads.
        </div>
    @endif
    <div class="metric-grid">
        <div class="card metric-card">
            <div class="card-body">
                <div class="metric-eyebrow">Registered Devices</div>
                <div class="metric-value">{{ $deviceStats['total'] }}</div>
                <div class="metric-note positive">
                    <i class="bi bi-broadcast"></i>
                    {{ $deviceStats['active'] }} active terminals
                </div>
            </div>
        </div>

        <div class="card metric-card">
            <div class="card-body">
                <div class="metric-eyebrow">Live Connectivity</div>
                <div class="metric-value">{{ $deviceStats['online'] }}</div>
                <div class="metric-note {{ ($deviceStats['offline'] ?? 0) > 0 ? 'negative' : 'positive' }}">
                    <i class="bi bi-router"></i>
                    {{ $deviceStats['offline'] }} offline devices
                </div>
            </div>
        </div>

        <div class="card metric-card">
            <div class="card-body">
                <div class="metric-eyebrow">Employee Directory</div>
                <div class="metric-value">{{ $employeeStats['total'] }}</div>
                <div class="metric-note positive">
                    <i class="bi bi-people"></i>
                    {{ $employeeStats['active'] }} active employees
                </div>
            </div>
        </div>

        <div class="card metric-card">
            <div class="card-body">
                <div class="metric-eyebrow">Today&apos;s Attendance</div>
                <div class="metric-value">{{ $attendanceStats['processed'] ?? 0 }}</div>
                <div class="metric-note {{ ($attendanceStats['pending'] ?? 0) > 0 ? 'negative' : 'positive' }}">
                    <i class="bi bi-clock-history"></i>
                    {{ $attendanceStats['pending'] ?? 0 }} pending records
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-7">
            <div class="card table-shell h-100">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">Recent Devices</h5>
                        <div class="shell-subtitle">Latest device activity and quick command dispatch.</div>
                    </div>
                    <a href="{{ route('admin.devices.index') }}" class="btn btn-outline-primary btn-sm">Open Devices</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Device</th>
                                    <th>Status</th>
                                    <th>Last Heartbeat</th>
                                    <th>Pending</th>
                                    <th>Command</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($recentDevices as $device)
                                    <tr>
                                        <td>
                                            <div class="fw-semibold">
                                                <a href="{{ route('admin.devices.show', $device) }}">{{ $device->name }}</a>
                                            </div>
                                            <div class="small"><code>{{ $device->serial_number }}</code></div>
                                        </td>
                                        <td>
                                            <span class="badge badge-{{ $device->isOnline() ? 'online' : 'offline' }}">
                                                {{ $device->isOnline() ? 'Online' : ucfirst($device->status) }}
                                            </span>
                                        </td>
                                        <td>{{ $device->last_heartbeat?->diffForHumans() ?? 'Never' }}</td>
                                        <td>
                                            @if($device->pending_command)
                                                <span class="badge bg-warning">{{ $device->pending_command }}</span>
                                            @else
                                                <span class="small text-muted">No queue backlog</span>
                                            @endif
                                        </td>
                                        <td style="min-width: 220px;">
                                            <form action="{{ route('admin.devices.command', $device) }}" method="POST" class="d-flex gap-2">
                                                @csrf
                                                <select name="command" class="form-select form-select-sm" required>
                                                    <option value="">Quick command</option>
                                                    <option value="REBOOT">Reboot</option>
                                                    <option value="UPDATE_TIME">Sync Time</option>
                                                    <option value="ClearAdmin">Clear Admin</option>
                                                    <option value="ENABLE_ATTENDANCE">Enable Realtime</option>
                                                    <option value="CLEAR_LOGS">Clear Logs</option>
                                                </select>
                                                <button type="submit" class="btn btn-primary btn-sm">Send</button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5">
                                            <div class="empty-state">
                                                <i class="bi bi-hdd-network"></i>
                                                <div class="fw-semibold">No devices registered yet</div>
                                                <div class="small">Register a terminal to start collecting attendance and biometric data.</div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-5">
            <div class="card table-shell h-100">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">Recent Attendance</h5>
                        <div class="shell-subtitle">Latest processed attendance transactions across active devices.</div>
                    </div>
                    <a href="{{ route('admin.attendance.index') }}" class="btn btn-outline-primary btn-sm">Open Attendance</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
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
                                        <td>{{ $log->log_time?->format('H:i:s') ?? '-' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3">
                                            <div class="empty-state">
                                                <i class="bi bi-calendar2-week"></i>
                                                <div class="fw-semibold">No attendance logs yet</div>
                                                <div class="small">This panel will populate as soon as devices begin posting ATTLOG records.</div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if(isset($unmatched) && $unmatched > 0)
        <div class="alert alert-warning mt-1">
            <i class="bi bi-exclamation-triangle me-2"></i>
            There are {{ $unmatched }} unmatched attendance logs awaiting review.
            <a href="{{ route('admin.logs.index') }}" class="alert-link">Open raw logs</a>
        </div>
    @endif
@endsection
