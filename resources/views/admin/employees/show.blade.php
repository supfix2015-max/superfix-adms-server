@extends('admin.layout')

@section('title', "Employee: {$employee->full_name}")
@section('page_title', $employee->full_name)
@section('content')
<div class="row g-4">
    {{-- Employee Details --}}
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Employee Details</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <strong>Employee ID:</strong><br>
                        <code>{{ $employee->employee_id }}</code>
                    </div>
                    <div class="col-md-6">
                        <strong>Status:</strong><br>
                        <span class="badge bg-{{ $employee->status === 'active' ? 'success' : ($employee->status === 'inactive' ? 'secondary' : 'warning') }}">
                            {{ ucfirst($employee->status) }}
                        </span>
                    </div>
                    <div class="col-md-6">
                        <strong>Full Name:</strong><br>
                        {{ $employee->full_name }}
                    </div>
                    <div class="col-md-6">
                        <strong>Email:</strong><br>
                        {{ $employee->email ?? 'Not set' }}
                    </div>
                    <div class="col-md-6">
                        <strong>Phone:</strong><br>
                        {{ $employee->phone ?? 'Not set' }}
                    </div>
                    <div class="col-md-6">
                        <strong>Department:</strong><br>
                        <span class="badge bg-light text-dark px-3 py-2">{{ $employee->department ?? 'Not assigned' }}</span>
                    </div>
                    <div class="col-md-6">
                        <strong>Device UID:</strong><br>
                        {{ $employee->uid ?? 'Not set' }}
                    </div>
                    <div class="col-md-6">
                        <strong>Card Number:</strong><br>
                        {{ $employee->card_no ?? 'Not set' }}
                    </div>
                    <div class="col-md-6">
                        <strong>Device Password:</strong><br>
                        {{ $employee->device_password ? 'Configured' : 'Not set' }}
                    </div>
                    <div class="col-md-6">
                        <strong>Privilege:</strong><br>
                        {{ $employee->device_privilege ?? 0 }}
                    </div>
                    <div class="col-md-6">
                        <strong>Group / TZ:</strong><br>
                        {{ $employee->group_code ?? '-' }} / {{ $employee->timezone_code ?? '-' }}
                    </div>
                    <div class="col-md-6">
                        <strong>Verify / Vice Card:</strong><br>
                        {{ $employee->verification_mode ?? '-' }} / {{ $employee->vice_card_no ?? '-' }}
                    </div>
                    <div class="col-md-6">
                        <strong>Hire Date:</strong><br>
                        {{ $employee->hire_date ? \Carbon\Carbon::parse($employee->hire_date)->format('M d, Y') : 'Not set' }}
                    </div>
                    <div class="col-md-6">
                        <strong>Access Window:</strong><br>
                        {{ $employee->valid_from?->format('M d, Y') ?? 'Any time' }} to {{ $employee->valid_until?->format('M d, Y') ?? 'Open-ended' }}
                    </div>
                    <div class="col-12">
                        <strong>Notes:</strong><br>
                        <div class="bg-light p-3 rounded">{{ $employee->notes ?? 'No notes' }}</div>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-top">
                    <div class="d-flex gap-2">
                        <a href="{{ route('admin.employees.edit', $employee) }}" class="btn btn-warning">
                            <i class="bi bi-pencil"></i> Edit Employee
                        </a>
                        <a href="{{ route('admin.employees.index') }}" class="btn btn-secondary">Back to List</a>
                        <form method="POST" action="{{ route('admin.employees.destroy', $employee) }}" class="d-inline" 
                              onsubmit="return confirm('Delete {{ $employee->full_name }}? All records will be preserved.');">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-danger">Delete Employee</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Quick Stats --}}
    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="card-title mb-0">Online Biometric Enrollment</h6>
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
                    <form method="POST" action="{{ route('admin.employees.biometric-enrollment', $employee) }}">
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
                            <div class="form-text">0-9 map to the stored finger positions supported by the device.</div>
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

                        <div class="form-text mt-3">
                            The device user must stand at the selected terminal and complete the prompt. The captured template will be stored automatically when the device uploads it back to ADMS.
                        </div>

                        <button type="submit" class="btn btn-success w-100 mt-3">
                            <i class="bi bi-fingerprint"></i> Start Enrollment
                        </button>
                    </form>
                @endif
            </div>
        </div>

        <div class="card h-100">
            <div class="card-header">
                <h6 class="card-title mb-0">Attendance Summary</h6>
            </div>
            <div class="card-body">
                <div class="row text-center g-3 mb-3">
                    <div class="col-6 border-end">
                        <div class="h6 mb-0">{{ $employee->attendanceLogs()->whereMonth('created_at', now()->month)->count() }}</div>
                        <small class="text-muted">This Month</small>
                    </div>
                    <div class="col-6">
                        <div class="h6 mb-0">{{ $employee->attendanceLogs()->whereYear('created_at', now()->year)->count() }}</div>
                        <small class="text-muted">This Year</small>
                    </div>
                </div>

                <h6 class="mb-2">Recent Activity</h6>
                @forelse($employee->attendanceLogs()->latest()->take(5)->get() as $log)
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom small">
                        <span>{{ $log->device->name ?? 'Unknown' }}</span>
                        <span class="text-end">
                            <span class="badge bg-{{ $log->log_type === 'checkin' ? 'success' : 'info' }} me-1">{{ ucfirst($log->log_type) }}</span>
                            <small class="text-muted">{{ $log->log_time?->format('M j, H:i') ?? $log->created_at->format('M j, H:i') }}</small>
                        </span>
                    </div>
                @empty
                    <div class="text-muted text-center py-3">No attendance records</div>
                @endforelse

                <h6 class="mb-2 mt-4">Biometric Templates</h6>
                <div class="row text-center g-3 mb-3">
                    <div class="col-4 border-end">
                        <div class="h6 mb-0">{{ $employee->biometrics()->where('biometric_type', 'fingerprint')->count() }}</div>
                        <small class="text-muted">Fingerprint</small>
                    </div>
                    <div class="col-4 border-end">
                        <div class="h6 mb-0">{{ $employee->biometrics()->where('biometric_type', 'face')->count() }}</div>
                        <small class="text-muted">Face</small>
                    </div>
                    <div class="col-4">
                        <div class="h6 mb-0">{{ $employee->biometrics()->where('biometric_type', 'photo')->count() }}</div>
                        <small class="text-muted">Photo</small>
                    </div>
                </div>

                @forelse($employee->biometrics as $biometric)
                    <div class="small border-top pt-2 mt-2">
                        <div><strong>{{ ucfirst($biometric->biometric_type) }}</strong> {{ $biometric->template_index ? "#{$biometric->template_index}" : '' }}</div>
                        <div class="text-muted">
                            {{ $biometric->device?->name ?? 'Unknown device' }}
                            @if($biometric->data_size)
                                • {{ $biometric->data_size }} bytes
                            @endif
                            @if(! is_null($biometric->is_valid))
                                • {{ $biometric->is_valid ? 'valid' : 'invalid' }}
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="text-muted text-center py-3">No biometric templates stored</div>
                @endforelse
            </div>
        </div>
    </div>
</div>

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
</script>
@endpush

@endsection
