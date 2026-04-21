@extends('admin.layout')

@section('title', "Employee: {$employee->full_name}")
@section('page_title', $employee->full_name)
@section('page_subtitle', 'Review employee identity, enrollment readiness, stored biometrics, attendance activity, and replay audit history from one profile.')

@php
    $statusClass = match ($employee->status) {
        'active' => 'success',
        'inactive' => 'secondary',
        default => 'warning',
    };

    $attendanceThisMonth = $employee->attendanceLogs()->whereMonth('created_at', now()->month)->count();
    $attendanceThisYear = $employee->attendanceLogs()->whereYear('created_at', now()->year)->count();
    $recentAttendance = $employee->attendanceLogs()->latest()->take(5)->get();

    $fingerprintCount = $employee->biometrics()->where('biometric_type', 'fingerprint')->count();
    $faceCount = $employee->biometrics()->where('biometric_type', 'face')->count();
    $photoCount = $employee->biometrics()->where('biometric_type', 'photo')->count();
    $storedBiometricCount = $fingerprintCount + $faceCount;
@endphp

@push('extra_css')
<style>
    .employee-show-shell {
        display: grid;
        grid-template-columns: minmax(0, 1.75fr) minmax(320px, 0.95fr);
        gap: 1.5rem;
        align-items: start;
    }

    .employee-show-main,
    .employee-show-sidebar {
        display: grid;
        gap: 1.5rem;
        align-content: start;
    }

    .employee-panel .card-header {
        padding: 1rem 1.25rem;
        background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        border-bottom: 1px solid #edf1f5;
    }

    .employee-panel .card-title {
        font-size: 1rem;
        font-weight: 600;
    }

    .employee-panel .card-subtitle {
        font-size: 0.85rem;
        color: #6c757d;
    }

    .employee-detail-grid,
    .employee-summary-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem 1.25rem;
    }

    .employee-field {
        padding-bottom: 0.85rem;
        border-bottom: 1px solid #edf1f5;
        min-width: 0;
    }

    .employee-field-label,
    .employee-summary-label,
    .employee-audit-table th {
        font-size: 0.75rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #6c757d;
    }

    .employee-field-label {
        margin-bottom: 0.35rem;
    }

    .employee-field-value {
        color: #1f2d3d;
        line-height: 1.45;
        word-break: break-word;
    }

    .employee-field-value.is-muted {
        color: #6c757d;
    }

    .employee-notes {
        padding: 1rem;
        border: 1px solid #edf1f5;
        border-radius: 0.85rem;
        background: #f8f9fb;
        color: #495057;
        min-height: 92px;
    }

    .employee-action-row {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        margin-top: 1.5rem;
        padding-top: 1.25rem;
        border-top: 1px solid #edf1f5;
    }

    .employee-form-note {
        font-size: 0.8125rem;
        line-height: 1.45;
        color: #6c757d;
    }

    .employee-summary-tile {
        padding: 0.9rem 1rem;
        border: 1px solid #edf1f5;
        border-radius: 0.85rem;
        background: #fbfcfe;
    }

    .employee-summary-label {
        margin-bottom: 0.35rem;
    }

    .employee-summary-value {
        font-size: 1.2rem;
        font-weight: 700;
        color: #1f2d3d;
        line-height: 1.1;
    }

    .employee-summary-meta {
        font-size: 0.8rem;
        color: #6c757d;
    }

    .employee-timeline-item,
    .employee-template-item {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        padding: 0.8rem 0;
        border-top: 1px solid #edf1f5;
    }

    .employee-timeline-item:first-child,
    .employee-template-item:first-child {
        border-top: none;
        padding-top: 0;
    }

    .employee-timeline-meta {
        font-size: 0.8rem;
        color: #6c757d;
        text-align: right;
        white-space: nowrap;
    }

    .employee-template-list {
        max-height: 420px;
        overflow: auto;
        padding-right: 0.25rem;
    }

    .employee-template-meta {
        font-size: 0.8rem;
        color: #6c757d;
    }

    .employee-audit-table th {
        white-space: nowrap;
    }

    .employee-audit-empty {
        padding: 1.5rem;
        text-align: center;
        color: #6c757d;
    }

    .employee-empty-state {
        display: grid;
        gap: 0.85rem;
        padding: 1rem;
        border: 1px dashed #d7dee7;
        border-radius: 0.9rem;
        background: linear-gradient(180deg, #fbfcfe 0%, #f5f8fb 100%);
    }

    .employee-empty-state-icon {
        width: 2.5rem;
        height: 2.5rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        background: #e9f2ff;
        color: #325d88;
        font-size: 1.1rem;
    }

    .employee-empty-state-title {
        font-size: 0.92rem;
        font-weight: 700;
        color: #1f2d3d;
    }

    .employee-empty-state-text {
        margin: 0;
        font-size: 0.84rem;
        line-height: 1.5;
        color: #6c757d;
    }

    .employee-empty-state-note {
        font-size: 0.78rem;
        color: #8a94a3;
    }

    @media (max-width: 1199.98px) {
        .employee-show-shell {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 767.98px) {
        .employee-detail-grid,
        .employee-summary-grid {
            grid-template-columns: 1fr;
        }

        .employee-action-row {
            flex-direction: column;
        }

        .employee-action-row > *,
        .employee-action-row form,
        .employee-action-row .btn {
            width: 100%;
        }

        .employee-timeline-item,
        .employee-template-item {
            flex-direction: column;
            gap: 0.35rem;
        }

        .employee-timeline-meta {
            text-align: left;
            white-space: normal;
        }
    }
</style>
@endpush

@section('content')
<div class="employee-show-shell">
    <div class="employee-show-main">
        <div class="card employee-panel">
            <div class="card-header d-flex justify-content-between align-items-start gap-3">
                <div>
                    <h5 class="card-title mb-1">Employee Details</h5>
                    <div class="card-subtitle">Profile, access configuration, and device identity.</div>
                </div>
                <span class="badge bg-{{ $statusClass }}">{{ ucfirst($employee->status) }}</span>
            </div>
            <div class="card-body">
                <div class="employee-detail-grid">
                    <div class="employee-field">
                        <div class="employee-field-label">Employee ID</div>
                        <div class="employee-field-value"><code>{{ $employee->employee_id }}</code></div>
                    </div>
                    <div class="employee-field">
                        <div class="employee-field-label">Full Name</div>
                        <div class="employee-field-value">{{ $employee->full_name }}</div>
                    </div>
                    <div class="employee-field">
                        <div class="employee-field-label">Email</div>
                        <div class="employee-field-value {{ $employee->email ? '' : 'is-muted' }}">{{ $employee->email ?? 'Not set' }}</div>
                    </div>
                    <div class="employee-field">
                        <div class="employee-field-label">Phone</div>
                        <div class="employee-field-value {{ $employee->phone ? '' : 'is-muted' }}">{{ $employee->phone ?? 'Not set' }}</div>
                    </div>
                    <div class="employee-field">
                        <div class="employee-field-label">Department</div>
                        <div class="employee-field-value">{{ $employee->department ?? 'Not assigned' }}</div>
                    </div>
                    <div class="employee-field">
                        <div class="employee-field-label">Hire Date</div>
                        <div class="employee-field-value {{ $employee->hire_date ? '' : 'is-muted' }}">
                            {{ $employee->hire_date ? \Carbon\Carbon::parse($employee->hire_date)->format('M d, Y') : 'Not set' }}
                        </div>
                    </div>
                    <div class="employee-field">
                        <div class="employee-field-label">Device UID</div>
                        <div class="employee-field-value">{{ $employee->uid ?? 'Not set' }}</div>
                    </div>
                    <div class="employee-field">
                        <div class="employee-field-label">Card Number</div>
                        <div class="employee-field-value">{{ $employee->card_no ?? 'Not set' }}</div>
                    </div>
                    <div class="employee-field">
                        <div class="employee-field-label">Device Password</div>
                        <div class="employee-field-value">{{ $employee->device_password ? 'Configured' : 'Not set' }}</div>
                    </div>
                    <div class="employee-field">
                        <div class="employee-field-label">Privilege</div>
                        <div class="employee-field-value">{{ $employee->device_privilege ?? 0 }}</div>
                    </div>
                    <div class="employee-field">
                        <div class="employee-field-label">Group / Time Zone</div>
                        <div class="employee-field-value">{{ $employee->group_code ?? '-' }} / {{ $employee->timezone_code ?? '-' }}</div>
                    </div>
                    <div class="employee-field">
                        <div class="employee-field-label">Verify / Vice Card</div>
                        <div class="employee-field-value">{{ $employee->verification_mode ?? '-' }} / {{ $employee->vice_card_no ?? '-' }}</div>
                    </div>
                    <div class="employee-field">
                        <div class="employee-field-label">Access Window</div>
                        <div class="employee-field-value">
                            {{ $employee->valid_from?->format('M d, Y') ?? 'Any time' }}
                            to
                            {{ $employee->valid_until?->format('M d, Y') ?? 'Open-ended' }}
                        </div>
                    </div>
                </div>

                <div class="mt-4">
                    <div class="employee-field-label">Notes</div>
                    <div class="employee-notes">{{ $employee->notes ?? 'No notes' }}</div>
                </div>

                <div class="employee-action-row">
                    <a href="{{ route('admin.employees.edit', $employee) }}" class="btn btn-warning">
                        <i class="bi bi-pencil"></i> Edit Employee
                    </a>
                    <a href="{{ route('admin.employees.index') }}" class="btn btn-secondary">Back to List</a>
                    <form
                        method="POST"
                        action="{{ route('admin.employees.destroy', $employee) }}"
                        onsubmit="return confirm('Delete {{ $employee->full_name }}? All records will be preserved.');"
                    >
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger">Delete Employee</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="card employee-panel">
            <div class="card-header d-flex justify-content-between align-items-start gap-3">
                <div>
                    <h6 class="card-title mb-1">Biometric Replay Audit</h6>
                    <div class="card-subtitle">Recent ADMS biometric push callbacks for this employee.</div>
                </div>
                <span class="badge bg-light text-dark">{{ $recentBiometricPushCommands->count() }} entries</span>
            </div>
            <div class="card-body p-0">
                @if($recentBiometricPushCommands->isEmpty())
                    <div class="employee-audit-empty">
                        No biometric replay commands have been queued for this employee yet.
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 align-middle employee-audit-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Target Device</th>
                                    <th>Type</th>
                                    <th>Slot</th>
                                    <th>Status</th>
                                    <th>Queued</th>
                                    <th>Ack</th>
                                    <th>Detail</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($recentBiometricPushCommands as $command)
                                    @php
                                        $responsePayload = is_array($command->response_payload) ? $command->response_payload : [];
                                        $statusClass = match ($command->status) {
                                            'done' => 'success',
                                            'failed' => 'danger',
                                            'sent' => 'primary',
                                            default => 'secondary',
                                        };
                                        $detailText = $command->error_message
                                            ?: ($responsePayload['Return'] ?? null)
                                            ?: $command->response
                                            ?: 'Awaiting callback';
                                    @endphp
                                    <tr>
                                        <td>
                                            <div class="fw-semibold">{{ $command->device?->name ?? 'Unknown device' }}</div>
                                            <div class="text-muted small">{{ $command->device?->serial_number ?? '-' }}</div>
                                        </td>
                                        <td>{{ ucfirst($command->parameters['biometric_type'] ?? 'Unknown') }}</td>
                                        <td>{{ $command->parameters['template_index'] ?? '-' }}</td>
                                        <td><span class="badge bg-{{ $statusClass }}">{{ ucfirst($command->status) }}</span></td>
                                        <td class="small text-muted">{{ $command->created_at?->format('Y-m-d H:i:s') ?? '-' }}</td>
                                        <td class="small text-muted">{{ $command->acknowledged_at?->format('Y-m-d H:i:s') ?? '-' }}</td>
                                        <td class="small">
                                            {{ $detailText }}
                                            @if($command->response && $command->response !== $detailText)
                                                <div class="text-muted">{{ $command->response }}</div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="border-top px-3 py-2 small text-muted">
                        A <strong>done</strong> status means the target device acknowledged the ADMS replay command. Physical verification should still be tested on mixed firmware families.
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="employee-show-sidebar">
        <div class="card employee-panel">
            <div class="card-header">
                <h6 class="card-title mb-1">Online Biometric Enrollment</h6>
                <div class="card-subtitle">Queue live fingerprint or face capture on an online terminal.</div>
            </div>
            <div class="card-body">
                @if(! $enrollmentPin)
                    <div class="alert alert-warning mb-0">
                        This employee does not have a numeric device PIN/UID yet. Sync or assign a numeric UID before starting fingerprint or face enrollment.
                    </div>
                @elseif($onlineDevices->isEmpty())
                    <div class="alert alert-warning mb-0">
                        No online devices are currently polling ADMS. Remote enrollment is available only while the target device is online.
                    </div>
                @else
                    <form
                        method="POST"
                        action="{{ route('admin.employees.biometric-enrollment', $employee) }}"
                        id="employeeBiometricEnrollmentForm"
                    >
                        @csrf
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Target Device</label>
                            <select name="device_id" class="form-select @error('device_id') is-invalid @enderror" required>
                                <option value="">Choose online device...</option>
                                @foreach($onlineDevices as $device)
                                    <option value="{{ $device->id }}" {{ (string) old('device_id', $employee->device_id) === (string) $device->id ? 'selected' : '' }}>
                                        {{ $device->name }} ({{ $device->serial_number }})
                                    </option>
                                @endforeach
                            </select>
                            @error('device_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Biometric Type</label>
                            <select name="biometric_type" id="biometricType" class="form-select @error('biometric_type') is-invalid @enderror" required>
                                <option value="fingerprint" {{ old('biometric_type', 'fingerprint') === 'fingerprint' ? 'selected' : '' }}>Fingerprint</option>
                                <option value="face" {{ old('biometric_type') === 'face' ? 'selected' : '' }}>Face</option>
                            </select>
                            @error('biometric_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3" id="fingerIndexGroup">
                            <label class="form-label fw-semibold">Fingerprint Slot</label>
                            <select name="finger_index" class="form-select @error('finger_index') is-invalid @enderror">
                                @for($i = 0; $i <= 9; $i++)
                                    <option value="{{ $i }}" {{ (string) old('finger_index', '0') === (string) $i ? 'selected' : '' }}>
                                        Finger #{{ $i }}
                                    </option>
                                @endfor
                            </select>
                            <div class="employee-form-note mt-1">0-9 map to the stored finger positions supported by the device.</div>
                            @error('finger_index')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label fw-semibold">PIN</label>
                                <input type="text" class="form-control" value="{{ $enrollmentPin }}" readonly>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold">Retries</label>
                                <input type="number" min="1" max="15" name="retry" class="form-control @error('retry') is-invalid @enderror" value="{{ old('retry', 3) }}">
                                @error('retry')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="form-check mt-3">
                            <input type="hidden" name="overwrite" value="0">
                            <input class="form-check-input" type="checkbox" name="overwrite" value="1" id="overwriteBiometric" {{ old('overwrite', '1') === '1' ? 'checked' : '' }}>
                            <label class="form-check-label" for="overwriteBiometric">
                                Overwrite existing biometric on device if present
                            </label>
                        </div>

                        @error('enrollment')
                            <div class="alert alert-danger mt-3 mb-0">{{ $message }}</div>
                        @enderror

                        <div class="employee-form-note mt-3">
                            The device user must stand at the selected terminal and complete the prompt. The captured template will be stored automatically when the device uploads it back to ADMS.
                        </div>

                        <button type="submit" class="btn btn-success w-100 mt-3">
                            <i class="bi bi-fingerprint"></i> Start Enrollment
                        </button>
                    </form>
                @endif
            </div>
        </div>

        <div class="card employee-panel">
            <div class="card-header d-flex justify-content-between align-items-start gap-3">
                <div>
                    <h6 class="card-title mb-1">Push Stored Biometrics</h6>
                    <div class="card-subtitle">Replay locally stored ADMS templates to other terminals.</div>
                </div>
                <span class="badge {{ $storedBiometricCount > 0 ? 'bg-light text-dark' : 'bg-secondary-subtle text-secondary-emphasis' }}">
                    {{ $storedBiometricCount > 0 ? "{$storedBiometricCount} stored" : 'Not ready yet' }}
                </span>
            </div>
            <div class="card-body">
                @if(! $enrollmentPin)
                    <div class="alert alert-warning mb-0">
                        This employee does not have a numeric device PIN/UID yet. ADMS replay requires a numeric PIN before queued biometric commands can be applied on other devices.
                    </div>
                @elseif($storedBiometricCount === 0)
                    <div class="employee-empty-state">
                        <div class="employee-empty-state-icon">
                            <i class="bi bi-cloud-arrow-down"></i>
                        </div>
                        <div class="employee-empty-state-title">No stored biometrics yet</div>
                        <p class="employee-empty-state-text">
                            This section stays disabled until the employee finishes one fingerprint or face enrollment on a device and that template is uploaded back to ADMS.
                        </p>
                        <div class="employee-empty-state-note">
                            Start enrollment above first. After the device sends the template back, the push form will appear here automatically.
                        </div>
                    </div>
                @else
                    <form method="POST" action="{{ route('admin.employees.push-biometrics', $employee) }}">
                        @csrf

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Template Type</label>
                            <select name="template_type" class="form-select @error('template_type') is-invalid @enderror" required>
                                <option value="all" {{ old('template_type', 'all') === 'all' ? 'selected' : '' }}>Face + Fingerprint</option>
                                <option value="fingerprint" {{ old('template_type') === 'fingerprint' ? 'selected' : '' }}>Fingerprint Only</option>
                                <option value="face" {{ old('template_type') === 'face' ? 'selected' : '' }}>Face Only</option>
                            </select>
                            @error('template_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="form-check">
                            <input type="hidden" name="online_only" value="0">
                            <input class="form-check-input" type="checkbox" name="online_only" value="1" id="pushOnlineOnly" {{ old('online_only', '1') === '1' ? 'checked' : '' }}>
                            <label class="form-check-label" for="pushOnlineOnly">
                                Queue only for devices currently online
                            </label>
                        </div>

                        <div class="form-check mt-2">
                            <input type="hidden" name="overwrite" value="0">
                            <input class="form-check-input" type="checkbox" name="overwrite" value="1" id="pushOverwrite" {{ old('overwrite', '1') === '1' ? 'checked' : '' }}>
                            <label class="form-check-label" for="pushOverwrite">
                                Overwrite existing biometric command fingerprints if this replay was already queued
                            </label>
                        </div>

                        @error('push_biometrics')
                            <div class="alert alert-danger mt-3 mb-0">{{ $message }}</div>
                        @enderror

                        <div class="employee-form-note mt-3">
                            This uses ADMS only. The server replays the stored template by queueing <code>SYNC_EMPLOYEES</code> and <code>SYNC_BIOMETRICS</code> commands to each target device.
                        </div>

                        <button type="submit" class="btn btn-primary w-100 mt-3">
                            <i class="bi bi-cloud-upload"></i> Push Stored Biometrics
                        </button>
                    </form>
                @endif
            </div>
        </div>

        <div class="card employee-panel">
            <div class="card-header">
                <h6 class="card-title mb-1">Attendance & Biometrics</h6>
                <div class="card-subtitle">Recent activity and currently stored templates.</div>
            </div>
            <div class="card-body">
                <div class="employee-summary-grid mb-4">
                    <div class="employee-summary-tile">
                        <div class="employee-summary-label">This Month</div>
                        <div class="employee-summary-value">{{ $attendanceThisMonth }}</div>
                        <div class="employee-summary-meta">attendance logs</div>
                    </div>
                    <div class="employee-summary-tile">
                        <div class="employee-summary-label">This Year</div>
                        <div class="employee-summary-value">{{ $attendanceThisYear }}</div>
                        <div class="employee-summary-meta">attendance logs</div>
                    </div>
                    <div class="employee-summary-tile">
                        <div class="employee-summary-label">Fingerprints</div>
                        <div class="employee-summary-value">{{ $fingerprintCount }}</div>
                        <div class="employee-summary-meta">stored templates</div>
                    </div>
                    <div class="employee-summary-tile">
                        <div class="employee-summary-label">Face / Photo</div>
                        <div class="employee-summary-value">{{ $faceCount }} / {{ $photoCount }}</div>
                        <div class="employee-summary-meta">stored assets</div>
                    </div>
                </div>

                <h6 class="mb-3">Recent Activity</h6>
                @forelse($recentAttendance as $log)
                    <div class="employee-timeline-item">
                        <div>
                            <div class="fw-semibold">{{ $log->device->name ?? 'Unknown device' }}</div>
                            <div class="text-muted small">{{ ucfirst($log->log_type) }} event</div>
                        </div>
                        <div class="employee-timeline-meta">
                            <span class="badge bg-{{ $log->log_type === 'checkin' ? 'success' : 'info' }}">{{ ucfirst($log->log_type) }}</span>
                            <div class="mt-1">{{ $log->log_time?->format('M j, H:i') ?? $log->created_at->format('M j, H:i') }}</div>
                        </div>
                    </div>
                @empty
                    <div class="text-muted small mb-4">No attendance records yet.</div>
                @endforelse

                <h6 class="mb-3 mt-4">Biometric Templates</h6>
                @if($employee->biometrics->isEmpty())
                    <div class="text-muted small">No biometric templates stored.</div>
                @else
                    <div class="employee-template-list">
                        @foreach($employee->biometrics as $biometric)
                            <div class="employee-template-item">
                                <div>
                                    <div class="fw-semibold">
                                        {{ ucfirst($biometric->biometric_type) }}
                                        @if($biometric->template_index)
                                            #{{ $biometric->template_index }}
                                        @endif
                                    </div>
                                    <div class="employee-template-meta">{{ $biometric->device?->name ?? 'Unknown device' }}</div>
                                </div>
                                <div class="employee-template-meta text-end">
                                    @if($biometric->data_size)
                                        <div>{{ $biometric->data_size }} bytes</div>
                                    @endif
                                    @if(! is_null($biometric->is_valid))
                                        <div>{{ $biometric->is_valid ? 'valid' : 'invalid' }}</div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

@include('admin.partials.biometric-enrollment-visualizer', ['modalId' => 'employeeBiometricEnrollmentModal'])

@push('extra_js')
<script>
const biometricTypeSelect = document.getElementById('biometricType');
const fingerIndexGroup = document.getElementById('fingerIndexGroup');

if (biometricTypeSelect && fingerIndexGroup) {
    const syncBiometricFormState = () => {
        fingerIndexGroup.style.display = biometricTypeSelect.value === 'fingerprint' ? '' : 'none';
    };

    biometricTypeSelect.addEventListener('change', syncBiometricFormState);
    syncBiometricFormState();
}

window.initBiometricEnrollmentVisualizer?.({
    formSelector: '#employeeBiometricEnrollmentForm',
    typeSelector: '#biometricType',
    modalId: 'employeeBiometricEnrollmentModal',
});
</script>
@endpush

@endsection
