@extends('admin.layout')

@section('title', 'Send Device Command')
@section('page_title', 'Send Command')
@section('content')
<div class="row">
    <div class="col-md-8 offset-md-2">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ route('admin.commands.store') }}">
                    @csrf

                    <div class="mb-4">
                        <label class="form-label fw-bold">Select Device *</label>
                        <select class="form-select @error('device_id') is-invalid @enderror" name="device_id" required>
                            <option value="">Choose device...</option>
                            @foreach($devices as $device)
                                <option value="{{ $device->id }}" {{ old('device_id') === $device->id ? 'selected' : '' }}>
                                    {{ $device->name }} ({{ $device->serial_number }})
                                    @if($device->status === 'offline')
                                        <span class="text-warning"> - Offline</span>
                                    @endif
                                </option>
                            @endforeach
                        </select>
                        @error('device_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold">Command *</label>
                        <select class="form-select @error('command') is-invalid @enderror" name="command" required>
                            <option value="">Select command...</option>
                            <option value="REBOOT" {{ old('command') === 'REBOOT' ? 'selected' : '' }}>🔄 Reboot Device</option>
                            <option value="SYNC" {{ old('command') === 'SYNC' ? 'selected' : '' }}>📱 Sync Attendance</option>
                            <option value="PULL_EMPLOYEES" {{ old('command') === 'PULL_EMPLOYEES' ? 'selected' : '' }}>👥 Pull Employees From Device</option>
                            <option value="PULL_ATTENDANCE" {{ old('command') === 'PULL_ATTENDANCE' ? 'selected' : '' }}>🕒 Pull Attendance From Device</option>
                            <option value="CLEAR_LOGS" {{ old('command') === 'CLEAR_LOGS' ? 'selected' : '' }}>🗑️ Clear Logs</option>
                            <option value="UPDATE_TIME" {{ old('command') === 'UPDATE_TIME' ? 'selected' : '' }}>⏰ Update Time</option>
                            <option value="GET_STATUS" {{ old('command') === 'GET_STATUS' ? 'selected' : '' }}>ℹ️ Get Status</option>
                            <option value="GET_EMPLOYEES" {{ old('command') === 'GET_EMPLOYEES' ? 'selected' : '' }}>👥 Get Employees</option>
                        </select>
                        @error('command')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div id="parameters-section" class="mb-4" style="display: none;">
                        <label class="form-label">Parameters</label>
                        <div id="parameters-fields">
                            <!-- Dynamic fields based on command -->
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Notes (optional)</label>
                        <textarea class="form-control @error('notes') is-invalid @enderror" name="notes" 
                                  rows="3" placeholder="Reason for command, expected behavior...">{{ old('notes') }}</textarea>
                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>Important:</strong> Commands are queued and processed asynchronously via 
                        <code>php artisan queue:work</code>. Check <a href="{{ route('admin.commands.index') }}">Commands</a> 
                        for status.
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success btn-lg">
                            <i class="bi bi-rocket-takeoff"></i> Queue Command
                        </button>
                        <a href="{{ route('admin.commands.index') }}" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@push('extra_js')
<script>
document.querySelector('[name="command"]').addEventListener('change', function() {
    const paramsSection = document.getElementById('parameters-section');
    const paramsFields = document.getElementById('parameters-fields');
    
    paramsFields.innerHTML = '';
    paramsSection.style.display = 'none';
    
    const command = this.value;
    if (!command) return;
    
    // Command-specific parameters
    const params = {
        'UPDATE_TIME': [
            {name: 'timestamp', label: 'Timestamp (optional, blank = server system time)', type: 'datetime-local'}
        ],
        'PULL_ATTENDANCE': [
            {name: 'start_time', label: 'Start Time (optional)', type: 'text'},
            {name: 'end_time', label: 'End Time (optional)', type: 'text'}
        ],
        'PULL_EMPLOYEES': [
            {name: 'employee_code', label: 'Employee ID / PIN (optional)', type: 'text'}
        ],
        'CLEAR_LOGS': [
            {name: 'days', label: 'Days back to clear', type: 'number', value: 30}
        ]
    };
    
    if (params[command]) {
        paramsSection.style.display = 'block';
        params[command].forEach(param => {
            paramsFields.innerHTML += `
                <div class="mb-2">
                    <label class="form-label small">${param.label}</label>
                    <input type="${param.type}" name="parameters[${param.name}]" class="form-control" 
                           value="${param.value || ''}" ${param.required ? 'required' : ''}>
                </div>
            `;
        });
    }
});
</script>
@endpush

@endsection
