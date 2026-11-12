@extends(config('legacy_attendance.browser_enabled', false) ? 'admin.layout_biocloud' : 'admin.layout')

@section('title', 'Edit Device')
@section('page_title', 'Edit Device Profile')
@section('page_subtitle', 'Update the terminal label or network reference without losing the command history, token, or synced activity tied to this device.')

@section('page_actions')
    <a href="{{ route('admin.devices.show', $device) }}" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>
        Back To Device
    </a>
@endsection

@section('content')
    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card shell-card">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">Device Configuration</h5>
                        <div class="shell-subtitle">Maintain a clean inventory record while preserving the existing terminal identity.</div>
                    </div>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.devices.update', $device) }}" class="panel-stack">
                        @csrf
                        @method('PUT')

                        <div>
                            <h6 class="mb-1">Terminal Identity</h6>
                            <p class="small mb-3">The serial number is retained as the canonical hardware identifier for this device.</p>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="name" class="form-label fw-semibold">Device Name</label>
                                    <input id="name" type="text" class="form-control @error('name') is-invalid @enderror" name="name" value="{{ old('name', $device->name) }}" required>
                                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label for="sn_display" class="form-label fw-semibold">Serial Number</label>
                                    <input id="sn_display" type="text" class="form-control bg-light" value="{{ $device->sn }}" readonly>
                                    <div class="form-text">Serial number stays locked after the device is registered.</div>
                                </div>
                            </div>
                        </div>

                        <div>
                            <h6 class="mb-1">Network Endpoint</h6>
                            <p class="small mb-3">Update the operator-facing network reference if the terminal moves or is re-addressed.</p>
                            <div class="row g-3">
                                <div class="col-md-8">
                                    <label for="ip_address" class="form-label fw-semibold">IP Address</label>
                                    <input id="ip_address" type="text" class="form-control @error('ip_address') is-invalid @enderror" name="ip_address" value="{{ old('ip_address', $device->ip_address) }}">
                                    @error('ip_address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-4">
                                    <label for="port" class="form-label fw-semibold">Port</label>
                                    <input id="port" type="number" class="form-control @error('port') is-invalid @enderror" name="port" value="{{ old('port', $device->port) }}" min="1" max="65535">
                                    @error('port')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>
                        </div>

                        <div class="border rounded p-3 bg-light">
                            <h6 class="mb-2">Edit License requirement</h6>
                            <input type="hidden" name="requires_license" value="0">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch"
                                    name="requires_license" id="requires_license" value="1"
                                    @checked(old('requires_license', $device->requires_license))>
                                <label class="form-check-label" for="requires_license">
                                    <strong>This device requires Edit License</strong>
                                </label>
                            </div>
                            <div class="form-text">
                                Leave unchecked when the terminal does not require a vendor license.
                                This setting is saved to the operational device inventory only.
                                It does not upload or activate a license on the physical terminal.
                            </div>
                        </div>

                        <div class="toolbar-cluster">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save me-1"></i>
                                Save Changes
                            </button>
                            <a href="{{ route('admin.devices.show', $device) }}" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card shell-card">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">Current Device State</h5>
                        <div class="shell-subtitle">Operational context while you edit the record.</div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="stat-stack">
                        <div class="stat-pill">
                            <strong>{{ $device->isOnline() ? 'Online' : ucfirst($device->status) }}</strong>
                            <span>Last heartbeat: {{ $device->last_heartbeat?->diffForHumans() ?? 'Never received' }}</span>
                        </div>
                        <div class="stat-pill">
                            <strong>{{ $device->connected_employees }}/{{ $device->capacity ?: '0' }}</strong>
                            <span>Employee slots currently associated with this terminal profile.</span>
                        </div>
                        <div class="stat-pill">
                            <strong>{{ $device->last_sync?->diffForHumans() ?? 'Never synced' }}</strong>
                            <span>Most recent device sync event recorded by the server.</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
