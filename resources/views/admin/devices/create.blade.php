@extends('admin.layout')

@section('title', 'Create Device')
@section('page_title', 'Register New Device')
@section('page_subtitle', 'Add a terminal to the enterprise fleet so ADMS can track heartbeats, queue commands, and receive attendance uploads.')

@section('page_actions')
    <a href="{{ route('admin.devices.index') }}" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>
        Back To Devices
    </a>
@endsection

@section('content')
    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card shell-card">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">Device Registration</h5>
                        <div class="shell-subtitle">Capture the network identity needed for polling, push commands, and status monitoring.</div>
                    </div>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.devices.store') }}" class="panel-stack">
                        @csrf

                        <div>
                            <h6 class="mb-1">Terminal Identity</h6>
                            <p class="small mb-3">Use a recognizable label and the exact device serial number reported by the terminal.</p>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="name" class="form-label fw-semibold">Device Name</label>
                                    <input id="name" type="text" class="form-control @error('name') is-invalid @enderror" name="name" value="{{ old('name') }}" placeholder="Front Gate Terminal" required>
                                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label for="sn" class="form-label fw-semibold">Serial Number</label>
                                    <input id="sn" type="text" class="form-control @error('sn') is-invalid @enderror" name="sn" value="{{ old('sn') }}" placeholder="ZKTeco serial number" required>
                                    @error('sn')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>
                        </div>

                        <div>
                            <h6 class="mb-1">Network Endpoint</h6>
                            <p class="small mb-3">This endpoint is used for diagnostics and operator reference while the device communicates with ADMS.</p>
                            <div class="row g-3">
                                <div class="col-md-8">
                                    <label for="ip_address" class="form-label fw-semibold">IP Address</label>
                                    <input id="ip_address" type="text" class="form-control @error('ip_address') is-invalid @enderror" name="ip_address" value="{{ old('ip_address') }}" placeholder="192.168.1.100" required>
                                    @error('ip_address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-4">
                                    <label for="port" class="form-label fw-semibold">Port</label>
                                    <input id="port" type="number" class="form-control @error('port') is-invalid @enderror" name="port" value="{{ old('port', config('zkteco.default_device_port', 8000)) }}" min="1" max="65535" required>
                                    @error('port')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>
                        </div>

                        <div class="toolbar-cluster">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-plus-circle me-1"></i>
                                Register Device
                            </button>
                            <a href="{{ route('admin.devices.index') }}" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card shell-card">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">What Happens Next</h5>
                        <div class="shell-subtitle">Provisioning notes for the new device record.</div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="stat-stack">
                        <div class="stat-pill">
                            <strong>API token is created automatically</strong>
                            <span>The server assigns a device token after registration so the terminal can authenticate future requests.</span>
                        </div>
                        <div class="stat-pill">
                            <strong>Heartbeat determines live status</strong>
                            <span>The device will show as online once it begins polling the ADMS endpoints with recent activity.</span>
                        </div>
                        <div class="stat-pill">
                            <strong>Commands queue after registration</strong>
                            <span>Once connected, the terminal can receive sync, pull, reboot, and cleanup commands from the admin workspace.</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
