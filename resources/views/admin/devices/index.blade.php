@extends('admin.layout')

@section('title', 'Devices')
@section('page_title', 'Device Management')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Devices</h1>
    <a href="{{ route('admin.devices.create') }}" class="btn btn-success">
        <i class="bi bi-plus-lg"></i> Add Device
    </a>
</div>

{{-- Filters --}}
<form method="GET" class="card mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <input type="text" name="search" class="form-control" placeholder="Search by name/SN..."
                    value="{{ $filters['search'] ?? '' }}">
            </div>
            <div class="col-md-2">
                <select name="status" class="form-select">
                    <option value="">All Status</option>
                    <option value="active" {{ ($filters['status'] ?? '') === 'active' ? 'selected' : '' }}>Active
                    </option>
                    <option value="inactive" {{ ($filters['status'] ?? '') === 'inactive' ? 'selected' : '' }}>Inactive
                    </option>
                    <option value="offline" {{ ($filters['status'] ?? '') === 'offline' ? 'selected' : '' }}>Offline
                    </option>
                </select>
            </div>
            <div class="col-md-2">
                <select name="device_type" class="form-select">
                    <option value="">All Types</option>
                    <option value="face" {{ ($filters['device_type'] ?? '') === 'face' ? 'selected' : '' }}>Face
                    </option>
                    <option value="fingerprint"
                        {{ ($filters['device_type'] ?? '') === 'fingerprint' ? 'selected' : '' }}>Fingerprint</option>
                    <option value="rfid" {{ ($filters['device_type'] ?? '') === 'rfid' ? 'selected' : '' }}>RFID
                    </option>
                    <option value="mixed" {{ ($filters['device_type'] ?? '') === 'mixed' ? 'selected' : '' }}>Mixed
                    </option>
                </select>
            </div>
            <div class="col-md-3">
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    @if (request()->hasAny(['search', 'status', 'device_type']))
                        <a href="{{ route('admin.devices.index') }}" class="btn btn-outline-secondary">Reset</a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</form>

{{-- Devices Table --}}
<div class="card">
    <div class="card-body">
        @if ($devices->count())
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Serial #</th>
                            <th>IP:Port</th>
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
                                    <a href="{{ route('admin.devices.show', $device) }}" class="fw-semibold">
                                        {{ $device->name }}
                                    </a>
                                </td>
                                <td><code class="text-muted">{{ $device->sn }}</code></td>
                                <td>
                                    <code>{{ $device->ip_address }}:{{ $device->port }}</code>
                                </td>
                                <td>
                                    <span
                                        class="badge bg-{{ $device->status === 'active' ? 'success' : ($device->status === 'offline' ? 'danger' : 'secondary') }}">
                                        {{ ucfirst($device->status) }}
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-info">{{ ucfirst($device->device_type) }}</span>
                                </td>
                                <td>
                                    {{ $device->last_heartbeat?->diffForHumans() ?? 'Never' }}
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <a href="{{ route('admin.devices.show', $device) }}"
                                            class="btn btn-outline-primary">View</a>
                                        <a href="{{ route('admin.devices.edit', $device) }}"
                                            class="btn btn-outline-warning">Edit</a>
                                        <form method="POST" action="{{ route('admin.devices.destroy', $device) }}"
                                            class="d-inline" onsubmit="return confirm('Delete this device?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-outline-danger">Delete</button>
                                        </form>
                                        <a href="{{ route('admin.devices.refresh-token', $device) }}"
                                            class="btn btn-outline-info">Token</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox display-4 opacity-25 mb-3 d-block"></i>
                                    <p>No devices found matching your filters.</p>
                                    <a href="{{ route('admin.devices.create') }}" class="btn btn-primary">Register
                                        First Device</a>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $devices->appends(request()->query())->links('pagination::bootstrap-5') }}
        @else
            <div class="text-center py-5">
                <i class="bi bi-tv display-1 text-muted mb-4"></i>
                <h5>No Devices Yet</h5>
                <p class="text-muted mb-4">Register your first ZKTeco device to get started.</p>
                <a href="{{ route('admin.devices.create') }}" class="btn btn-success btn-lg">
                    <i class="bi bi-plus-lg"></i> Register Device
                </a>
            </div>
        @endif
    </div>
</div>

@endsection