@extends('admin.layout')

@section('title', 'ZKTeco Command Center')
@section('page_title', 'ZKTeco Command Center')
@section('page_subtitle', 'Available original device operations, individually queued and delivered only after an authenticated terminal polls the ADMS server.')

@section('page_actions')
    <a href="{{ route('admin.commands.index') }}" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-list-check me-1"></i> Command Queue / History
    </a>
@endsection

@section('content')
    <div class="alert {{ config('adms_pilot.enabled') && config('zkteco.dispatch_commands') ? 'alert-info' : 'alert-warning' }}">
        <i class="bi bi-shield-lock me-1"></i>
        <strong>Command delivery:</strong>
        @if (config('adms_pilot.enabled') && config('zkteco.dispatch_commands'))
            Enabled for eligible devices, but a valid per-device token is still required before a command is sent.
        @else
            Not enabled yet. Commands are listed for inspection, but the form will not queue work until reception and dispatch are explicitly enabled.
        @endif
        No command is sent merely by opening this page. Individual device firmware may not support every operation.
    </div>

    <div class="row g-4">
        <div class="col-xl-7">
            <div class="card table-shell">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">Choose a device and operation</h5>
                        <div class="shell-subtitle">Devices not yet online remain visible for preparation and future authenticated polling.</div>
                    </div>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.commands.store') }}" id="command-form">
                        @csrf
                        <div class="mb-3">
                            <label for="cmd-device" class="form-label fw-semibold">Target device</label>
                            <select class="form-select @error('device_id') is-invalid @enderror" id="cmd-device" name="device_id" required>
                                <option value="">Choose a terminal…</option>
                                @foreach ($devices as $device)
                                    <option value="{{ $device->id }}" @selected((string) old('device_id', request('device_id')) === (string) $device->id)>
                                        {{ $device->name }} — {{ $device->serial_number ?: $device->sn }}{{ $device->isOnline() ? ' • Receiving now' : ' • No recent contact' }}
                                    </option>
                                @endforeach
                            </select>
                            @error('device_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-3">
                            <label for="cmd-command" class="form-label fw-semibold">Supported operation</label>
                            <select class="form-select @error('command') is-invalid @enderror" id="cmd-command" name="command" required>
                                <option value="">Choose an operation…</option>
                                @foreach ($availableCommands as $code => $label)
                                    <option value="{{ $code }}" @selected(old('command') === $code)>{{ $label }} ({{ $code }})</option>
                                @endforeach
                            </select>
                            @error('command') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div id="employee-params" class="mb-3 d-none">
                            <label for="employee-code" class="form-label fw-semibold">Employee PIN (optional)</label>
                            <input id="employee-code" type="text" name="parameters[employee_code]" value="{{ old('parameters.employee_code') }}" class="form-control" maxlength="64" placeholder="Blank = request all users">
                        </div>
                        <div id="attendance-params" class="mb-3 d-none">
                            <label class="form-label fw-semibold">Attendance history window (optional)</label>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <input type="text" class="form-control" name="parameters[start_time]" value="{{ old('parameters.start_time') }}" placeholder="Start: YYYY-MM-DD HH:MM:SS">
                                </div>
                                <div class="col-md-6">
                                    <input type="text" class="form-control" name="parameters[end_time]" value="{{ old('parameters.end_time') }}" placeholder="End: YYYY-MM-DD HH:MM:SS">
                                </div>
                            </div>
                            <div class="form-text">Leave blank to use the device's default history scope. Large history pulls may take time.</div>
                        </div>
                        <div class="mb-3">
                            <label for="cmd-notes" class="form-label fw-semibold">Operation notes</label>
                            <textarea class="form-control" id="cmd-notes" name="notes" rows="2" maxlength="500" placeholder="Reason for sending the command…">{{ old('notes') }}</textarea>
                        </div>
                        <div id="sensitive-warning" class="alert alert-danger d-none">
                            <strong>Device-affecting action.</strong> This may interrupt attendance or erase device logs.
                            <label class="d-flex align-items-center gap-2 mt-2">
                                <input type="checkbox" name="confirm_sensitive" value="yes" @checked(old('confirm_sensitive') === 'yes')>
                                I understand this will affect the selected physical terminal
                            </label>
                        </div>
                        @error('confirm_sensitive') <div class="text-danger small mb-3">{{ $message }}</div> @enderror
                        <div class="d-flex gap-2 flex-wrap">
                            <button type="submit" class="btn btn-primary" @disabled(!config('adms_pilot.enabled') || !config('zkteco.dispatch_commands'))>
                                <i class="bi bi-send me-1"></i> Queue for selected device
                            </button>
                            <a href="{{ route('admin.commands.index') }}" class="btn btn-outline-secondary">View command history</a>
                        </div>
                        <p class="small text-secondary mt-3 mb-0">
                            Queuing is not execution. Commands are sent only on authenticated <code>/iclock/getrequest</code> and results appear after device replies.
                        </p>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="card table-shell">
                <div class="card-header"><h5 class="shell-title">ZKTeco operation reference</h5></div>
                <div class="card-body">
                    <p class="small text-secondary">Available in the command form above:</p>
                    @foreach ($availableCommands as $code => $label)
                        <div class="d-flex justify-content-between gap-2 py-2 border-bottom">
                            <span>{{ $label }}</span>
                            <code class="text-nowrap">{{ $code }}</code>
                        </div>
                    @endforeach
                    <p class="small text-secondary mt-4">These additional operations require employee or biometric-specific data and are handled by the corresponding workflows:</p>
                    @foreach ($advancedCommands as $code => $label)
                        <div class="d-flex justify-content-between gap-2 py-2 border-bottom">
                            <span>{{ $label }}</span>
                            <code class="text-nowrap">{{ $code }}</code>
                        </div>
                    @endforeach
                    <div class="small text-secondary mt-3">
                        Operation support varies by device model and firmware. No arbitrary raw command entry is accepted on this page.
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('extra_js')
<script>
(function () {
    const control = document.getElementById('cmd-command');
    const employee = document.getElementById('employee-params');
    const attendance = document.getElementById('attendance-params');
    const sensitive = document.getElementById('sensitive-warning');
    const dangerous = new Set(['RESTART','CLEAR_LOGS','DISABLE_ATTENDANCE','RM_ADMIN_PRIVILEGE']);
    function refresh() {
        const type = control.value;
        employee.classList.toggle('d-none', type !== 'PULL_EMPLOYEES');
        attendance.classList.toggle('d-none', type !== 'PULL_ATTENDANCE');
        sensitive.classList.toggle('d-none', !dangerous.has(type));
    }
    control.addEventListener('change', refresh);
    refresh();
})();
</script>
@endpush
