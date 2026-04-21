@extends('admin.layout')

@section('title', 'Send Device Command')
@section('page_title', 'Queue Device Command')
@section('page_subtitle', 'Compose an operational command, target a device, and enqueue it for ADMS delivery on the next poll cycle.')

@section('content')
    <div class="row justify-content-center">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">Command Composer</h5>
                        <div class="shell-subtitle">Use this form for direct administrative actions such as reboot, time sync, data pull, or log cleanup.</div>
                    </div>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.commands.store') }}">
                        @csrf

                        <div class="mb-4">
                            <label class="form-label fw-semibold">Select Device</label>
                            <select class="form-select @error('device_id') is-invalid @enderror" name="device_id" required>
                                <option value="">Choose device...</option>
                                @foreach($devices as $device)
                                    <option value="{{ $device->id }}" {{ (string) old('device_id') === (string) $device->id ? 'selected' : '' }}>
                                        {{ $device->name }} ({{ $device->serial_number }}){{ $device->status === 'offline' ? ' - Offline' : '' }}
                                    </option>
                                @endforeach
                            </select>
                            @error('device_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold">Command</label>
                            <select class="form-select @error('command') is-invalid @enderror" name="command" required>
                                <option value="">Select command...</option>
                                <option value="REBOOT" {{ old('command') === 'REBOOT' ? 'selected' : '' }}>Reboot Device</option>
                                <option value="SYNC" {{ old('command') === 'SYNC' ? 'selected' : '' }}>Sync Attendance</option>
                                <option value="PULL_EMPLOYEES" {{ old('command') === 'PULL_EMPLOYEES' ? 'selected' : '' }}>Pull Employees From Device</option>
                                <option value="PULL_ATTENDANCE" {{ old('command') === 'PULL_ATTENDANCE' ? 'selected' : '' }}>Pull Attendance From Device</option>
                                <option value="CLEAR_LOGS" {{ old('command') === 'CLEAR_LOGS' ? 'selected' : '' }}>Clear Logs</option>
                                <option value="UPDATE_TIME" {{ old('command') === 'UPDATE_TIME' ? 'selected' : '' }}>Update Time</option>
                                <option value="GET_STATUS" {{ old('command') === 'GET_STATUS' ? 'selected' : '' }}>Get Status</option>
                                <option value="GET_EMPLOYEES" {{ old('command') === 'GET_EMPLOYEES' ? 'selected' : '' }}>Get Employees</option>
                            </select>
                            @error('command')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div id="parameters-section" class="mb-4" style="display: none;">
                            <label class="form-label fw-semibold">Optional Parameters</label>
                            <div id="parameters-fields" class="row g-2"></div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold">Notes</label>
                            <textarea class="form-control @error('notes') is-invalid @enderror" name="notes" rows="4" placeholder="Reason for the command, expected outcome, or operator notes...">{{ old('notes') }}</textarea>
                            @error('notes')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="alert alert-warning mb-4">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            Commands are queued and executed asynchronously. The target terminal must poll <code>/iclock/getrequest</code> before delivery occurs.
                        </div>

                        <div class="toolbar-cluster">
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-rocket-takeoff me-1"></i>
                                Queue Command
                            </button>
                            <a href="{{ route('admin.commands.index') }}" class="btn btn-outline-secondary">Cancel</a>
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

                const params = {
                    'UPDATE_TIME': [
                        {name: 'timestamp', label: 'Timestamp', type: 'datetime-local'}
                    ],
                    'PULL_ATTENDANCE': [
                        {name: 'start_time', label: 'Start Time', type: 'text'},
                        {name: 'end_time', label: 'End Time', type: 'text'}
                    ],
                    'PULL_EMPLOYEES': [
                        {name: 'employee_code', label: 'Employee ID / PIN', type: 'text'}
                    ],
                    'CLEAR_LOGS': [
                        {name: 'days', label: 'Days Back To Clear', type: 'number', value: 30}
                    ]
                };

                if (params[command]) {
                    paramsSection.style.display = 'block';
                    params[command].forEach((param) => {
                        paramsFields.innerHTML += `
                            <div class="col-md-6">
                                <label class="form-label small">${param.label}</label>
                                <input type="${param.type}" name="parameters[${param.name}]" class="form-control" value="${param.value || ''}">
                            </div>
                        `;
                    });
                }
            });
        </script>
    @endpush
@endsection
