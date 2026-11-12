@extends('admin.layout')

@section('title', 'ADMS · Devices')
@section('page_title', 'Device Fleet & Licenses')
@section('page_subtitle', 'Manage registered ZKTeco terminals, license requirements, recent ADMS connectivity, and authorized commands from one workspace.')

@section('page_actions')
    <a class="btn btn-outline-primary" href="{{ route('admin.device-connectivity.index') }}"><i class="bi bi-broadcast-pin me-1"></i> Connection Status</a>
    <a class="btn btn-outline-secondary" href="{{ route('admin.commands.index') }}"><i class="bi bi-terminal me-1"></i> Command Center</a>
    <a class="btn btn-primary" href="{{ route('admin.devices.create') }}"><i class="bi bi-plus-lg me-1"></i> Register Device</a>
@endsection

@section('content')
<style>
.bc-fleet-toolbar{display:flex;gap:8px;flex-wrap:wrap;padding:12px 14px;background:var(--bs-body-bg,#fff);border-bottom:1px solid #dee7f1}
.bc-fleet-toolbar .btn{font-size:12px}
.bc-fleet-table{font-size:12px;min-width:1160px;margin:0}
.bc-fleet-table thead th{background:#f1f5fb;color:#334155;white-space:nowrap;font-weight:700;font-size:11px}
.bc-fleet-table td{vertical-align:middle;padding:7px}
.bc-license-toggle{display:flex;align-items:center;gap:7px;white-space:nowrap}
.bc-license-toggle .form-check-input{margin:0;cursor:pointer}
.bc-fleet-table .form-select{font-size:12px}
.bc-state-live{background:#e0f8ec;color:#126746;border-radius:3px;padding:4px 7px}
.bc-state-off{background:#eaf0f6;color:#394860;border-radius:3px;padding:4px 7px}
</style>

<div class="card p-0 overflow-hidden">
    <div class="bc-fleet-toolbar">
        <form action="{{ route('admin.devices.index') }}" method="GET" class="d-flex align-items-center gap-2 flex-wrap">
            <input name="search" class="form-control form-control-sm" style="width:210px" placeholder="Serial / Device Alias"
                   value="{{ $filters['search'] ?? '' }}">
            <select name="connection" class="form-select form-select-sm" style="width:135px">
                <option value="">All connections</option>
                <option value="online" @selected(($filters['connection'] ?? '') === 'online')>Recent contact</option>
                <option value="offline" @selected(($filters['connection'] ?? '') === 'offline')>No recent contact</option>
            </select>
            <select name="license" class="form-select form-select-sm" style="width:150px">
                <option value="">All licenses</option>
                <option value="required" @selected(($filters['license'] ?? '') === 'required')>Edit License needed</option>
                <option value="not_required" @selected(($filters['license'] ?? '') === 'not_required')>No license needed</option>
            </select>
            <button class="btn btn-success btn-sm" type="submit">Search</button>
            <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.devices.index') }}">Reset</a>
        </form>
        <a href="{{ route('admin.device-connectivity.index') }}" class="btn btn-outline-primary btn-sm ms-auto">
            <i class="bi bi-broadcast me-1"></i> Connection audit
        </a>
        <a href="{{ route('admin.commands.index') }}" class="btn btn-outline-primary btn-sm">Command log</a>
    </div>

    @unless ($dispatchEnabled)
        <div class="alert alert-warning rounded-0 m-0 py-2 small">
            <strong>Command delivery is locked.</strong>
            Live attendance reception and hourly time-only synchronization continue.
            Remote reboot, clear, transfer and user actions require authenticated tokens and explicit command-dispatch approval.
            Nothing on this page silently enables dispatch.
        </div>
    @endunless

    <div class="table-responsive">
        <table class="table table-sm table-striped table-hover bc-fleet-table">
            <thead>
                <tr>
                    <th>Serial Number</th>
                    <th>Device Alias</th>
                    <th>Last ADMS Update (UAE)</th>
                    <th>Connection</th>
                    <th>Registered</th>
                    <th>Edit License required?</th>
                    <th>Device Menu</th>
                    <th>Data Transfer</th>
                    <th>Clear Data</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($devices as $row)
                    <tr>
                        <td><code>{{ $row['serial'] }}</code></td>
                        <td class="fw-semibold">{{ $row['name'] }}
                            @unless ($row['original'])
                                <div class="text-muted fw-normal">ADMS-only / office test</div>
                            @endunless
                        </td>
                        <td class="text-nowrap">{{ $row['last_seen'] ?: 'Never connected' }}</td>
                        <td>
                            <span class="{{ $row['online'] ? 'bc-state-live' : 'bc-state-off' }}">
                                {{ $row['online'] ? 'Recent contact' : 'No recent contact' }}
                            </span>
                        </td>
                        <td>{{ $row['original'] ? 'Original SQL' : 'ADMS only' }}</td>
                        <td>
                            @if ($row['id'])
                                <form class="bc-license-toggle" method="POST"
                                      action="{{ route('admin.fleet.license', $row['id']) }}">
                                    @csrf
                                    <input type="hidden" name="requires_license" value="0">
                                    <input type="checkbox" class="form-check-input" name="requires_license" value="1"
                                           id="license-{{ $row['id'] }}" @checked($row['requires_license'])>
                                    <label for="license-{{ $row['id'] }}" class="m-0 small">
                                        {{ $row['requires_license'] ? 'Required' : 'Not required' }}
                                    </label>
                                    <button type="submit" class="btn btn-outline-success btn-sm" title="Save Edit License flag only">
                                        Save
                                    </button>
                                </form>
                            @else
                                <span class="text-muted">Register in ADMS first</span>
                            @endif
                        </td>
                        <td>
                            @if ($row['id'])
                                <div class="btn-group btn-group-sm">
                                    <a class="btn btn-outline-secondary" href="{{ route('admin.devices.edit', $row['id']) }}">
                                        <i class="bi bi-pencil"></i> Edit Device
                                    </a>
                                    <button class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split"
                                            data-bs-toggle="dropdown" aria-expanded="false"><span class="visually-hidden">Device actions</span></button>
                                    <ul class="dropdown-menu">
                                        <li><a class="dropdown-item" href="{{ route('admin.devices.show', $row['id']) }}">
                                            <i class="bi bi-info-circle me-1"></i> Read Information
                                        </a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <form method="POST" action="{{ route('admin.commands.store') }}"
                                                  onsubmit="return confirm('Queue REBOOT for {{ $row['serial'] }}?')">
                                                @csrf
                                                <input type="hidden" name="device_id" value="{{ $row['id'] }}">
                                                <input type="hidden" name="command" value="RESTART">
                                                <input type="hidden" name="confirm_sensitive" value="yes">
                                                <button class="dropdown-item" @disabled(! $dispatchEnabled)>Reboot</button>
                                            </form>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <form method="POST"
                                                  action="{{ route('admin.fleet.cancel-pending', $row['id']) }}"
                                                  onsubmit="return confirm('Cancel all UNSENT pending commands for {{ $row['serial'] }}? Sent commands cannot be recalled.')">
                                                @csrf
                                                <input type="hidden" name="confirmation" value="CANCEL-PENDING">
                                                <button class="dropdown-item" type="submit">
                                                    Clear Pending Command
                                                </button>
                                            </form>
                                        </li>
                                        <li><a class="dropdown-item" href="{{ route('admin.commands.index') }}">
                                            Command status / pending history
                                        </a></li>
                                    </ul>
                                </div>
                            @else — @endif
                        </td>
                        <td>
                            @if ($row['id'])
                                <div class="dropdown">
                                    <button class="btn btn-outline-primary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                                        Data Transfer
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li>
                                            <form method="POST" action="{{ route('admin.commands.store') }}">
                                                @csrf
                                                <input type="hidden" name="device_id" value="{{ $row['id'] }}">
                                                <input type="hidden" name="command" value="PULL_EMPLOYEES">
                                                <button class="dropdown-item" @disabled(! $dispatchEnabled)>
                                                    Download Employees from Device
                                                </button>
                                            </form>
                                        </li>
                                        <li>
                                            <form method="POST" action="{{ route('admin.commands.store') }}">
                                                @csrf
                                                <input type="hidden" name="device_id" value="{{ $row['id'] }}">
                                                <input type="hidden" name="command" value="PULL_ATTENDANCE">
                                                <button class="dropdown-item" @disabled(! $dispatchEnabled)>
                                                    Download Transactions
                                                </button>
                                            </form>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item" href="{{ route('admin.commands.create') }}">
                                            Advanced authenticated sync…
                                        </a></li>
                                    </ul>
                                </div>
                            @else — @endif
                        </td>
                        <td>
                            @if ($row['id'])
                                <div class="dropdown">
                                    <button class="btn btn-outline-danger btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                                        Clear Data
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li>
                                            <form method="POST" action="{{ route('admin.commands.store') }}"
                                                onsubmit="return confirm('WARNING: DELETE attendance logs from physical device {{ $row['serial'] }}? Only after verifying SQL backup.')">
                                                @csrf
                                                <input type="hidden" name="device_id" value="{{ $row['id'] }}">
                                                <input type="hidden" name="command" value="CLEAR_LOGS">
                                                <input type="hidden" name="confirm_sensitive" value="yes">
                                                <button class="dropdown-item text-danger" @disabled(! $dispatchEnabled)>
                                                    Clear Attendance Data
                                                </button>
                                            </form>
                                        </li>
                                        <li><span class="dropdown-item disabled">Clear All Data — not supported</span></li>
                                    </ul>
                                </div>
                            @else — @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">No devices matched the selected filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 p-3">
        <span class="text-muted small">
            {{ $devices->total() }} devices · All matching uses exact serial number. Last contact does not prove device authentication.
            License checkbox is an operator flag only, not vendor activation.
        </span>
        {{ $devices->links('pagination::bootstrap-5') }}
    </div>
</div>
@endsection
