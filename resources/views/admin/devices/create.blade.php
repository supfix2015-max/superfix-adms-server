@extends('admin.layout')

@section('title', 'Create Device')
@section('page_title', 'Register New Device')

@section('content')
<div class="row">
    <div class="col-md-8 offset-md-2">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ route('admin.devices.store') }}">
                    @csrf

                    <div class="mb-3">
                        <label class="form-label">Device Name *</label>
                        <input type="text" class="form-control @error('name') is-invalid @enderror" 
                               name="name" value="{{ old('name') }}" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Serial Number *</label>
                        <input type="text" class="form-control @error('sn') is-invalid @enderror" 
                               name="sn" value="{{ old('sn') }}" required>
                        @error('sn')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">IP Address *</label>
                                <input type="text" class="form-control @error('ip_address') is-invalid @enderror" 
                                       name="ip_address" value="{{ old('ip_address') }}" placeholder="192.168.1.100" required>
                                @error('ip_address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Port *</label>
                                <input type="number" class="form-control @error('port') is-invalid @enderror" 
                                       name="port" value="{{ old('port', config('zkteco.default_device_port', 8000)) }}" min="1" max="65535" required>
                                @error('port')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Register Device</button>
                        <a href="{{ route('admin.devices.index') }}" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
