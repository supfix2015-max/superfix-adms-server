@extends('admin.layout')

@section('title', 'Devices')
@section('page_title', 'Device Fleet')
@section('page_subtitle', 'Track terminal health, connectivity, and command workflows across the full registered device fleet.')

@section('page_actions')
    <a href="{{ route('admin.devices.create') }}" class="btn btn-success">
        <i class="bi bi-plus-lg me-1"></i>
        Register Device
    </a>
@endsection

@section('content')
    <div class="card">
        <div class="card-body">
            <div class="page-toolbar">
                <div>
                    <h5 class="mb-1">Fleet Filters</h5>
                    <p class="small mb-0">Search by device name, serial number, endpoint, status, or device class.</p>
                </div>
            </div>

            <form method="GET" class="row g-3 mt-2">
                <div class="col-lg-5">
                    <input type="text" name="search" class="form-control" placeholder="Search by name, serial, or IP..." value="{{ $filters['search'] ?? '' }}">
                </div>
                <div class="col-sm-6 col-lg-2">
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="active" {{ ($filters['status'] ?? '') === 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ ($filters['status'] ?? '') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                        <option value="offline" {{ ($filters['status'] ?? '') === 'offline' ? 'selected' : '' }}>Offline</option>
                    </select>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <select name="device_type" class="form-select">
                        <option value="">All Types</option>
                        <option value="face" {{ ($filters['device_type'] ?? '') === 'face' ? 'selected' : '' }}>Face</option>
                        <option value="fingerprint" {{ ($filters['device_type'] ?? '') === 'fingerprint' ? 'selected' : '' }}>Fingerprint</option>
                        <option value="rfid" {{ ($filters['device_type'] ?? '') === 'rfid' ? 'selected' : '' }}>RFID</option>
                        <option value="mixed" {{ ($filters['device_type'] ?? '') === 'mixed' ? 'selected' : '' }}>Mixed</option>
                    </select>
                </div>
                <div class="col-lg-3">
                    <div class="toolbar-cluster">
                        <button type="submit" class="btn btn-primary">Apply Filters</button>
                        @if (request()->hasAny(['search', 'status', 'device_type']))
                            <a href="{{ route('admin.devices.index') }}" class="btn btn-outline-secondary">Reset</a>
                        @endif
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card table-shell">
        <div class="card-header">
            <div>
                <h5 class="shell-title">Registered Devices</h5>
                <div class="shell-subtitle">{{ $devices->total() }} devices are currently tracked in the ADMS fleet registry.</div>
            </div>
        </div>
        <div class="card-body p-0">
            @if ($devices->count())
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Serial</th>
                                <th>Endpoint</th>
                                <th>Status</th>
                                <th>Type</th>
                                <th>Last Seen</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($devices as $device)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">
                                            <a href="{{ route('admin.devices.show', $device) }}">{{ $device->name }}</a>
                                        </div>
                                    </td>
                                    <td><code>{{ $device->sn }}</code></td>
                                    <td><code>{{ $device->ip_address }}:{{ $device->port }}</code></td>
                                    <td>
                                        <span class="badge bg-{{ $device->status === 'active' ? 'success' : ($device->status === 'offline' ? 'danger' : 'secondary') }}">
                                            {{ ucfirst($device->status) }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-info">{{ ucfirst($device->device_type ?: 'Unknown') }}</span>
                                    </td>
                                    <td>{{ $device->last_heartbeat?->diffForHumans() ?? 'Never' }}</td>
                                    <td>
                                        <div class="toolbar-cluster">
                                            <a href="{{ route('admin.devices.show', $device) }}" class="btn btn-sm btn-outline-primary">View</a>
                                            <a href="{{ route('admin.devices.edit', $device) }}" class="btn btn-sm btn-outline-warning">Edit</a>
                                            <a href="{{ route('admin.devices.refresh-token', $device) }}" class="btn btn-sm btn-outline-info">Token</a>
                                            <form method="POST" action="{{ route('admin.devices.destroy', $device) }}" class="d-inline" onsubmit="return confirm('Delete this device?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7">
                                        <div class="empty-state">
                                            <i class="bi bi-hdd-network"></i>
                                            <div class="fw-semibold">No devices matched these filters</div>
                                            <div class="small">Adjust the fleet filters or register a new terminal to expand the dataset.</div>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @else
                <div class="empty-state">
                    <i class="bi bi-hdd-rack"></i>
                    <div class="fw-semibold">No devices registered yet</div>
                    <div class="small mb-3">Start by adding your first ZKTeco terminal to the ADMS workspace.</div>
                    <a href="{{ route('admin.devices.create') }}" class="btn btn-success">
                        <i class="bi bi-plus-lg me-1"></i>
                        Register Device
                    </a>
                </div>
            @endif
        </div>
        @if ($devices->count())
            <div class="card-footer">
                {{ $devices->appends(request()->query())->links('pagination::bootstrap-5') }}
            </div>
        @endif
    </div>
@endsection
