@extends('admin.layout')

@section('title', 'Device Details')
@section('page_title', $device->name)
@section('page_subtitle', 'Inspect connectivity, attendance traffic, queued commands, and device-side data pulls from a single terminal detail view.')

@section('page_actions')
    <div class="toolbar-cluster">
        <a href="{{ route('admin.devices.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>
            Back To Devices
        </a>
        <a href="{{ route('admin.devices.edit', $device) }}" class="btn btn-primary">
            <i class="bi bi-pencil-square me-1"></i>
            Edit Device
        </a>
    </div>
@endsection

@section('content')
    <div class="metric-grid">
        <div class="card metric-card">
            <div class="card-body">
                <div class="metric-eyebrow">Connectivity</div>
                <div class="metric-value">{{ $device->isOnline() ? 'Online' : 'Offline' }}</div>
                <div class="metric-note {{ $device->isOnline() ? 'positive' : 'negative' }}">
                    <i class="bi bi-broadcast"></i>
                    {{ $device->last_heartbeat?->diffForHumans() ?? 'No heartbeat yet' }}
                </div>
            </div>
        </div>

        <div class="card metric-card">
            <div class="card-body">
                <div class="metric-eyebrow">Attendance Logs</div>
                <div class="metric-value">{{ $stats['attendance_total'] }}</div>
                <div class="metric-note {{ ($stats['attendance_unmatched'] ?? 0) > 0 ? 'negative' : 'positive' }}">
                    <i class="bi bi-calendar2-check"></i>
                    {{ $stats['attendance_unmatched'] }} unmatched entries
                </div>
            </div>
        </div>

        <div class="card metric-card">
            <div class="card-body">
                <div class="metric-eyebrow">ADMS Requests</div>
                <div class="metric-value">{{ $stats['raw_total'] }}</div>
                <div class="metric-note {{ ($stats['unresolved_errors'] ?? 0) > 0 ? 'negative' : 'positive' }}">
                    <i class="bi bi-journal-code"></i>
                    {{ $stats['raw_processed'] }} processed
                </div>
            </div>
        </div>

        <div class="card metric-card">
            <div class="card-body">
                <div class="metric-eyebrow">Synced Employees</div>
                <div class="metric-value">{{ $stats['employee_total'] }}</div>
                <div class="metric-note {{ ($device->connected_employees ?? 0) > 0 ? 'positive' : '' }}">
                    <i class="bi bi-people"></i>
                    Capacity {{ $device->connected_employees }}/{{ $device->capacity ?: '0' }}
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card shell-card">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">Device Overview</h5>
                        <div class="shell-subtitle">Identity, telemetry, and sync markers recorded for this terminal.</div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="stat-pill h-100">
                                <span>Device Name</span>
                                <strong>{{ $device->name }}</strong>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="stat-pill h-100">
                                <span>Serial Number</span>
                                <strong><code>{{ $device->sn }}</code></strong>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="stat-pill h-100">
                                <span>Network</span>
                                <strong>{{ $device->ip_address }}:{{ $device->port }}</strong>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="stat-pill h-100">
                                <span>Device Type</span>
                                <strong>{{ ucfirst($device->device_type ?? 'terminal') }}</strong>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="stat-pill h-100">
                                <span>Last Sync</span>
                                <strong>{{ $device->last_sync?->diffForHumans() ?? 'Never synced' }}</strong>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="stat-pill h-100">
                                <span>Firmware</span>
                                <strong>{{ $device->firmware_version ?? 'Not reported' }}</strong>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="stat-pill h-100">
                                <span>Created</span>
                                <strong>{{ $device->created_at->format('Y-m-d H:i:s') }}</strong>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="stat-pill h-100">
                                <span>Status</span>
                                <strong>{{ $device->isOnline() ? 'Online' : ucfirst($device->status) }}</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card table-shell">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">Recent Attendance Logs</h5>
                        <div class="shell-subtitle">Latest attendance records received from this terminal.</div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Time</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($device->attendanceLogs as $log)
                                    <tr>
                                        <td>{{ $log->employee?->full_name ?? $log->employee_code ?? 'Unmatched' }}</td>
                                        <td>{{ $log->log_time?->format('Y-m-d H:i:s') ?? '-' }}</td>
                                        <td>{{ ucfirst($log->log_type ?? 'unknown') }}</td>
                                        <td>
                                            <span class="badge bg-{{ $log->status === 'processed' ? 'success' : 'warning' }}">
                                                {{ ucfirst($log->status) }}
                                            </span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4">
                                            <div class="empty-state">
                                                <i class="bi bi-calendar2-week"></i>
                                                <div class="fw-semibold">No attendance logs received yet</div>
                                                <div class="small">This terminal has {{ $stats['raw_total'] }} raw ADMS request(s) and {{ $stats['employee_total'] }} synced employee(s) so far.</div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card table-shell">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">Recent ADMS Requests</h5>
                        <div class="shell-subtitle">Server-side ingest history for the last device callbacks.</div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Endpoint</th>
                                    <th>Status</th>
                                    <th>Records</th>
                                    <th>Received</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($device->rawLogs as $rawLog)
                                    <tr>
                                        <td><code>{{ $rawLog->endpoint }}</code></td>
                                        <td>
                                            <span class="badge bg-{{ $rawLog->processing_status === 'processed' ? 'success' : ($rawLog->processing_status === 'failed' ? 'danger' : 'secondary') }}">
                                                {{ ucfirst($rawLog->processing_status) }}
                                            </span>
                                        </td>
                                        <td>{{ $rawLog->record_count }}</td>
                                        <td>{{ $rawLog->created_at->format('Y-m-d H:i:s') }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4">
                                            <div class="empty-state">
                                                <i class="bi bi-journal-code"></i>
                                                <div class="fw-semibold">No raw ADMS requests yet</div>
                                                <div class="small">The device has not posted any request payloads to the server.</div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card table-shell">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">Synced Employees</h5>
                        <div class="shell-subtitle">Recently observed employee records associated with this terminal.</div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Employee ID</th>
                                    <th>Name</th>
                                    <th>UID</th>
                                    <th>Card</th>
                                    <th>Updated</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($recentEmployees as $employee)
                                    <tr>
                                        <td><code>{{ $employee->employee_id }}</code></td>
                                        <td>{{ $employee->name }}</td>
                                        <td>{{ $employee->uid ?? '-' }}</td>
                                        <td>{{ $employee->card_no ?? '-' }}</td>
                                        <td>{{ $employee->updated_at?->format('Y-m-d H:i:s') ?? '-' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5">
                                            <div class="empty-state">
                                                <i class="bi bi-people"></i>
                                                <div class="fw-semibold">No employee sync data yet</div>
                                                <div class="small">Employee snapshots will appear here after pull or sync operations complete.</div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card table-shell">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">Device Commands</h5>
                        <div class="shell-subtitle">Latest commands queued or acknowledged by this device.</div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Command</th>
                                    <th>Status</th>
                                    <th>Sent</th>
                                    <th>Acknowledged</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($device->commands as $cmd)
                                    <tr>
                                        <td>{{ \Illuminate\Support\Str::headline($cmd->command_type ?? $cmd->command) }}</td>
                                        <td>
                                            <span class="badge bg-{{ $cmd->status === 'done' ? 'success' : ($cmd->status === 'failed' ? 'danger' : 'secondary') }}">
                                                {{ ucfirst($cmd->status) }}
                                            </span>
                                        </td>
                                        <td>{{ $cmd->sent_at?->format('Y-m-d H:i:s') ?? '-' }}</td>
                                        <td>{{ $cmd->acknowledged_at?->format('Y-m-d H:i:s') ?? '-' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4">
                                            <div class="empty-state">
                                                <i class="bi bi-terminal"></i>
                                                <div class="fw-semibold">No device commands recorded</div>
                                                <div class="small">Queue a command from the action panels to start building command history for this terminal.</div>
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

        <div class="col-xl-4">
            <div class="card shell-card">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">Device Actions</h5>
                        <div class="shell-subtitle">Maintain the device profile and token lifecycle.</div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="panel-stack">
                        <a href="{{ route('admin.devices.edit', $device) }}" class="btn btn-primary w-100">
                            <i class="bi bi-pencil me-1"></i>
                            Edit Device
                        </a>
                        <form method="POST" action="{{ route('admin.devices.refresh-token', $device) }}" onsubmit="return confirm('Regenerate token?')">
                            @csrf
                            <button type="submit" class="btn btn-warning w-100">
                                <i class="bi bi-key me-1"></i>
                                Refresh API Token
                            </button>
                        </form>
                        <form method="POST" action="{{ route('admin.devices.destroy', $device) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger w-100" onclick="return confirm('Delete device?')">
                                <i class="bi bi-trash me-1"></i>
                                Delete Device
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="card shell-card">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">API Token</h5>
                        <div class="shell-subtitle">Use this token on the terminal when configuring ADMS access.</div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="stat-pill">
                        <span>Current Token</span>
                        <strong><code>{{ $device->api_token }}</code></strong>
                    </div>
                    <div class="form-text mt-3">If the terminal no longer authenticates correctly, regenerate the token and update the device configuration.</div>
                </div>
            </div>

            <div class="card shell-card">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">Pull Data</h5>
                        <div class="shell-subtitle">Request a callback upload on the next device poll.</div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="panel-stack">
                        <form method="POST" action="{{ route('admin.devices.pull', $device) }}">
                            @csrf
                            <input type="hidden" name="dataset" value="employees">
                            <button type="submit" class="btn btn-success w-100" onclick="return confirm('Queue employee pull from device?')">
                                <i class="bi bi-download me-1"></i>
                                Pull Employees
                            </button>
                        </form>
                        <form method="POST" action="{{ route('admin.devices.pull', $device) }}">
                            @csrf
                            <input type="hidden" name="dataset" value="attendance">
                            <button type="submit" class="btn btn-outline-success w-100" onclick="return confirm('Queue attendance pull from device?')">
                                <i class="bi bi-clock-history me-1"></i>
                                Pull Attendance
                            </button>
                        </form>
                    </div>
                    <div class="form-text mt-3">The terminal must poll <code>/iclock/getrequest</code> before the server receives the requested payload.</div>
                </div>
            </div>

            <div class="card shell-card">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">Operational Stats</h5>
                        <div class="shell-subtitle">High-level counters for troubleshooting and audit.</div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="list-card">
                        <div class="list-row">
                            <div>
                                <div class="list-row-title">Attendance Logs</div>
                                <div class="list-row-meta">Processed attendance records</div>
                            </div>
                            <strong>{{ $stats['attendance_total'] }}</strong>
                        </div>
                        <div class="list-row">
                            <div>
                                <div class="list-row-title">Unmatched Logs</div>
                                <div class="list-row-meta">Transactions without an employee match</div>
                            </div>
                            <strong>{{ $stats['attendance_unmatched'] }}</strong>
                        </div>
                        <div class="list-row">
                            <div>
                                <div class="list-row-title">ADMS Requests</div>
                                <div class="list-row-meta">Total ingested payloads</div>
                            </div>
                            <strong>{{ $stats['raw_total'] }}</strong>
                        </div>
                        <div class="list-row">
                            <div>
                                <div class="list-row-title">Unresolved Errors</div>
                                <div class="list-row-meta">Outstanding device-side exceptions</div>
                            </div>
                            <strong>{{ $stats['unresolved_errors'] }}</strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
