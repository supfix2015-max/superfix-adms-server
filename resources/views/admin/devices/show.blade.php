@extends('admin.layout')

@section('title', 'Device Details')
@section('page_title', 'Device: ' . $device->name)

@section('content')
<div class="row">
    <div class="col-md-8">
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="mb-0">Device Information</h5>
            </div>
            <div class="card-body">
                <dl class="row">
                    <dt class="col-sm-3">Name</dt>
                    <dd class="col-sm-9"><strong>{{ $device->name }}</strong></dd>

                    <dt class="col-sm-3">Serial Number</dt>
                    <dd class="col-sm-9"><code>{{ $device->sn }}</code></dd>

                    <dt class="col-sm-3">IP Address</dt>
                    <dd class="col-sm-9">{{ $device->ip_address }}:{{ $device->port }}</dd>

                    <dt class="col-sm-3">Type</dt>
                    <dd class="col-sm-9">{{ ucfirst($device->device_type) }}</dd>

                    <dt class="col-sm-3">Status</dt>
                    <dd class="col-sm-9">
                        <span class="badge badge-{{ $device->isOnline() ? 'online' : ($device->status === 'inactive' ? 'inactive' : 'offline') }}">
                            {{ ucfirst($device->status) }}
                        </span>
                    </dd>

                    <dt class="col-sm-3">Online</dt>
                    <dd class="col-sm-9">
                        @if($device->isOnline())
                        <span class="badge bg-success">Yes - Last: {{ $device->last_heartbeat->diffForHumans() }}</span>
                        @else
                        <span class="badge bg-danger">No - Last: {{ $device->last_heartbeat?->diffForHumans() ?? 'Never' }}</span>
                        @endif
                    </dd>

                    <dt class="col-sm-3">Capacity</dt>
                    <dd class="col-sm-9">{{ $device->connected_employees }}/{{ $device->capacity }}</dd>

                    <dt class="col-sm-3">Firmware</dt>
                    <dd class="col-sm-9">{{ $device->firmware_version ?? '-' }}</dd>

                    <dt class="col-sm-3">API Token</dt>
                    <dd class="col-sm-9">
                        <code style="font-size: 0.75rem;">{{ substr($device->api_token, 0, 16) }}...</code>
                        <a href="{{ route('admin.devices.refresh-token', $device) }}" class="btn btn-sm btn-warning" onclick="return confirm('Refresh token?')">Regenerate</a>
                    </dd>

                    <dt class="col-sm-3">Last Sync</dt>
                    <dd class="col-sm-9">{{ $device->last_sync?->diffForHumans() ?? 'Never' }}</dd>

                    <dt class="col-sm-3">Created</dt>
                    <dd class="col-sm-9">{{ $device->created_at->format('Y-m-d H:i:s') }}</dd>
                </dl>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Recent Attendance Logs</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
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
                            <td>{{ $log->log_time->format('Y-m-d H:i:s') }}</td>
                            <td>{{ ucfirst($log->log_type) }}</td>
                            <td>
                                <span class="badge bg-{{ $log->status === 'processed' ? 'success' : 'warning' }}">
                                    {{ ucfirst($log->status) }}
                                </span>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted">
                                No attendance logs yet. This device has {{ $stats['raw_total'] }} ADMS request(s) and {{ $stats['employee_total'] }} synced employee(s).
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0">Recent ADMS Requests</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
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
                            <td colspan="4" class="text-center text-muted">No raw ADMS requests</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="mb-0">Actions</h5>
            </div>
            <div class="card-body">
                <a href="{{ route('admin.devices.edit', $device) }}" class="btn btn-primary w-100 mb-2">
                    <i class="bi bi-pencil"></i> Edit Device
                </a>
                <a href="{{ route('admin.devices.refresh-token', $device) }}" class="btn btn-warning w-100 mb-2" onclick="return confirm('Regenerate token?')">
                    <i class="bi bi-key"></i> Refresh API Token
                </a>
                <form method="POST" action="{{ route('admin.devices.destroy', $device) }}">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-danger w-100" onclick="return confirm('Delete device?')">
                        <i class="bi bi-trash"></i> Delete Device
                    </button>
                </form>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">
                <h5 class="mb-0">Pull Data</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.devices.pull', $device) }}" class="mb-2">
                    @csrf
                    <input type="hidden" name="dataset" value="employees">
                    <button type="submit" class="btn btn-success w-100" onclick="return confirm('Queue employee pull from device?')">
                        <i class="bi bi-download"></i> Pull Employees
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.devices.pull', $device) }}">
                    @csrf
                    <input type="hidden" name="dataset" value="attendance">
                    <button type="submit" class="btn btn-outline-success w-100" onclick="return confirm('Queue attendance pull from device?')">
                        <i class="bi bi-clock-history"></i> Pull Attendance
                    </button>
                </form>
                <div class="form-text mt-2">
                    The terminal must poll <code>/iclock/getrequest</code> before the server can receive the requested data.
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Statistics</h5>
            </div>
            <div class="card-body">
                <p>
                    <strong>Attendance Logs:</strong><br>
                    {{ $stats['attendance_total'] }}
                </p>
                <p>
                    <strong>Unmatched:</strong><br>
                    {{ $stats['attendance_unmatched'] }}
                </p>
                <p>
                    <strong>ADMS Requests:</strong><br>
                    {{ $stats['raw_total'] }} total / {{ $stats['raw_processed'] }} processed
                </p>
                <p>
                    <strong>Synced Employees:</strong><br>
                    {{ $stats['employee_total'] }}
                </p>
                <p>
                    <strong>Errors:</strong><br>
                    {{ $stats['unresolved_errors'] }}
                </p>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-12 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Synced Employees</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
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
                            <td colspan="5" class="text-center text-muted">No synced employees</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Device Commands</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
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
                            <td><span class="badge bg-secondary">{{ ucfirst($cmd->status) }}</span></td>
                            <td>{{ $cmd->sent_at?->format('Y-m-d H:i:s') ?? '-' }}</td>
                            <td>{{ $cmd->acknowledged_at?->format('Y-m-d H:i:s') ?? '-' }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted">No commands</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
