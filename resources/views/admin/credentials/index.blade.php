@extends('admin.layout')

@section('title', 'Credentials')
@section('page_title', 'Credential Management')
@section('page_subtitle', 'Manage cards, passcodes, common codes, and online biometric enrollment from a single access-control workspace.')

@section('content')
@if($error)
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-2"></i>
        {{ $error }}
    </div>
@endif

<div class="row g-3">
    <div class="col-xl-4">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-semibold">
                    <i class="bi bi-plus-circle me-2 text-success"></i>
                    Add Or Update Credential
                </h6>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.credentials.store') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fw-medium">Employee <span class="text-danger">*</span></label>
                        <select name="employee_id" class="form-select @error('employee_id') is-invalid @enderror" required>
                            <option value="">Select employee</option>
                            @foreach($employees as $employee)
                                <option value="{{ $employee->id }}" {{ (string) old('employee_id') === (string) $employee->id ? 'selected' : '' }}>
                                    {{ $employee->full_name ?: $employee->employee_id }} ({{ $employee->employee_id }})
                                </option>
                            @endforeach
                        </select>
                        @error('employee_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Credential Type <span class="text-danger">*</span></label>
                        <select name="type" id="credentialType" class="form-select @error('type') is-invalid @enderror" required>
                            <option value="CARD" {{ old('type', 'CARD') === 'CARD' ? 'selected' : '' }}>Card</option>
                            <option value="PASSCODE" {{ old('type') === 'PASSCODE' ? 'selected' : '' }}>Passcode</option>
                            <option value="COMMON" {{ old('type') === 'COMMON' ? 'selected' : '' }}>Common Code</option>
                        </select>
                        @error('type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium" id="credentialValueLabel">Card Number <span class="text-danger">*</span></label>
                        <input
                            type="text"
                            id="credentialValue"
                            name="credential_value"
                            class="form-control @error('credential_value') is-invalid @enderror"
                            value="{{ old('credential_value') }}"
                            placeholder="Enter card number"
                            required
                        >
                        <div class="form-text" id="credentialValueHelp">
                            This updates the selected credential field on the employee record used for device sync.
                        </div>
                        @error('credential_value')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-medium">Effective Date</label>
                            <input
                                type="date"
                                name="effective_date"
                                class="form-control @error('effective_date') is-invalid @enderror"
                                value="{{ old('effective_date', now()->toDateString()) }}"
                            >
                            @error('effective_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-medium">Expiration Date</label>
                            <input
                                type="date"
                                name="expiration_date"
                                class="form-control @error('expiration_date') is-invalid @enderror"
                                value="{{ old('expiration_date', now()->addYear()->toDateString()) }}"
                            >
                            @error('expiration_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <button type="submit" class="btn btn-success w-100" {{ $employees->isEmpty() ? 'disabled' : '' }}>
                        <i class="bi bi-save me-1"></i>
                        Save Credential
                    </button>
                </form>
            </div>
        </div>

        <div class="card shadow-sm mb-3" id="biometric-enrollment">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-semibold">
                    <i class="bi bi-fingerprint me-2 text-success"></i>
                    Online Biometric Enrollment
                </h6>
            </div>
            <div class="card-body">
                @if($employees->isEmpty())
                    <div class="alert alert-warning mb-0">
                        No employees are available for biometric enrollment.
                    </div>
                @elseif($onlineDevices->isEmpty())
                    <div class="alert alert-warning mb-0">
                        No online devices are currently polling ADMS. Remote enrollment is available only while the target device is online.
                    </div>
                @else
                    <form
                        method="POST"
                        action="{{ route('admin.credentials.biometric-enrollment') }}"
                        id="credentialBiometricEnrollmentForm"
                    >
                        @csrf
                        <div class="mb-3">
                            <label class="form-label fw-medium">Employee <span class="text-danger">*</span></label>
                            <select
                                name="enrollment_employee_id"
                                id="enrollmentEmployee"
                                class="form-select @error('enrollment_employee_id') is-invalid @enderror"
                                required
                            >
                                <option value="">Select employee</option>
                                @foreach($employees as $employee)
                                    @php($enrollmentPin = preg_replace('/[^0-9]/', '', (string) ($employee->sync_uid ?: $employee->uid ?: $employee->employee_id)))
                                    <option
                                        value="{{ $employee->id }}"
                                        data-pin="{{ $enrollmentPin !== '' ? substr($enrollmentPin, 0, 32) : '' }}"
                                        {{ (string) old('enrollment_employee_id') === (string) $employee->id ? 'selected' : '' }}
                                    >
                                        {{ $employee->full_name ?: $employee->employee_id }} ({{ $employee->employee_id }})
                                    </option>
                                @endforeach
                            </select>
                            @error('enrollment_employee_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-medium">Target Device <span class="text-danger">*</span></label>
                            <select name="enrollment_device_id" class="form-select @error('enrollment_device_id') is-invalid @enderror" required>
                                <option value="">Choose online device...</option>
                                @foreach($onlineDevices as $device)
                                    <option value="{{ $device->id }}" {{ (string) old('enrollment_device_id') === (string) $device->id ? 'selected' : '' }}>
                                        {{ $device->name }} ({{ $device->serial_number }})
                                    </option>
                                @endforeach
                            </select>
                            @error('enrollment_device_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-medium">Biometric Type <span class="text-danger">*</span></label>
                            <select name="biometric_type" id="credentialBiometricType" class="form-select @error('biometric_type') is-invalid @enderror" required>
                                <option value="fingerprint" {{ old('biometric_type', 'fingerprint') === 'fingerprint' ? 'selected' : '' }}>Fingerprint</option>
                                <option value="face" {{ old('biometric_type') === 'face' ? 'selected' : '' }}>Face</option>
                            </select>
                            @error('biometric_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3" id="credentialFingerIndexGroup">
                            <label class="form-label fw-medium">Fingerprint Slot</label>
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
                                <label class="form-label fw-medium">PIN</label>
                                <input type="text" id="credentialEnrollmentPin" class="form-control" value="" readonly>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-medium">Retries</label>
                                <input
                                    type="number"
                                    min="1"
                                    max="15"
                                    name="retry"
                                    class="form-control @error('retry') is-invalid @enderror"
                                    value="{{ old('retry', 3) }}"
                                >
                                @error('retry')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="alert alert-warning mt-3 mb-0 d-none" id="credentialEnrollmentPinAlert">
                            The selected employee does not have a numeric device PIN/UID yet. Sync or assign a numeric UID before starting fingerprint or face enrollment.
                        </div>

                        <div class="form-check mt-3">
                            <input type="hidden" name="overwrite" value="0">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                name="overwrite"
                                value="1"
                                id="credentialEnrollmentOverwrite"
                                {{ old('overwrite', '1') === '1' ? 'checked' : '' }}
                            >
                            <label class="form-check-label" for="credentialEnrollmentOverwrite">
                                Overwrite existing biometric on device if present
                            </label>
                        </div>

                        @error('enrollment')
                            <div class="alert alert-danger mt-3 mb-0">{{ $message }}</div>
                        @enderror

                        <div class="form-text mt-3">
                            The user must stand at the selected terminal and complete the prompt. The captured template will be stored automatically when the device uploads it back to ADMS.
                        </div>

                        <button type="submit" class="btn btn-success w-100 mt-3" id="credentialEnrollmentSubmit">
                            <i class="bi bi-fingerprint me-1"></i>
                            Start Enrollment
                        </button>
                    </form>
                @endif
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold">
                    <i class="bi bi-person-x me-2 text-warning"></i>
                    No-Credential Employees
                </h6>
                <span class="badge bg-warning text-dark">{{ $noCredentialEmployees->count() }}</span>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Employees with no card, passcode, or common code configured.
                </p>
                <a href="{{ route('admin.credentials.no-credential') }}" class="btn btn-outline-warning btn-sm">
                    <i class="bi bi-search me-1"></i>
                    View List
                </a>
            </div>
        </div>
    </div>

    <div class="col-xl-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-semibold">
                    <i class="bi bi-credit-card-2-front me-2 text-primary"></i>
                    Configured Credentials ({{ $credentials->count() }})
                </h6>
            </div>
            <div class="card-body p-0">
                @if($credentials->isEmpty())
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-credit-card fs-1 d-block mb-3 opacity-25"></i>
                        No credentials configured yet.
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Employee</th>
                                    <th>Type</th>
                                    <th>Value</th>
                                    <th>Effective</th>
                                    <th>Expires</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($credentials as $credential)
                                    <tr>
                                        <td>
                                            <div class="fw-medium small">{{ $credential['employee']->full_name ?: 'Unnamed Employee' }}</div>
                                            <div class="text-muted" style="font-size:.75rem">{{ $credential['employee']->employee_id }}</div>
                                        </td>
                                        <td>
                                            <span class="badge bg-{{ $credential['type'] === 'CARD' ? 'primary' : ($credential['type'] === 'PASSCODE' ? 'success' : 'secondary') }}">
                                                {{ $credential['type'] }}
                                            </span>
                                        </td>
                                        <td class="font-monospace small">{{ $credential['display_value'] }}</td>
                                        <td class="text-muted small">{{ $credential['effective_date'] ?? 'Any time' }}</td>
                                        <td class="text-muted small">{{ $credential['expiration_date'] ?? 'Open-ended' }}</td>
                                        <td>
                                            <span class="badge bg-{{ $credential['status_class'] }}">
                                                {{ $credential['status'] }}
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <form
                                                method="POST"
                                                action="{{ route('admin.credentials.destroy', ['employee' => $credential['employee'], 'type' => $credential['type']]) }}"
                                                onsubmit="return confirm('Remove this credential?')"
                                            >
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        <div class="card shadow-sm mt-3" id="no-credential">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-semibold">
                    <i class="bi bi-people me-2 text-warning"></i>
                    Employees Without Credentials ({{ $noCredentialEmployees->count() }})
                </h6>
            </div>
            <div class="card-body p-0">
                @if($noCredentialEmployees->isEmpty())
                    <div class="text-center text-muted py-4">
                        Every employee has at least one credential configured.
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Employee ID</th>
                                    <th>Name</th>
                                    <th>Status</th>
                                    <th>Department</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($noCredentialEmployees as $employee)
                                    <tr>
                                        <td class="font-monospace small">{{ $employee->employee_id }}</td>
                                        <td>{{ $employee->full_name ?: 'Unnamed Employee' }}</td>
                                        <td>
                                            <span class="badge bg-{{ $employee->status === 'active' ? 'success' : 'secondary' }}">
                                                {{ strtoupper((string) $employee->status) }}
                                            </span>
                                        </td>
                                        <td>{{ $employee->department ?: '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

@include('admin.partials.biometric-enrollment-visualizer', ['modalId' => 'credentialBiometricEnrollmentModal'])

@endsection

@push('extra_js')
<script>
const credentialType = document.getElementById('credentialType');
const credentialValueLabel = document.getElementById('credentialValueLabel');
const credentialValue = document.getElementById('credentialValue');
const credentialValueHelp = document.getElementById('credentialValueHelp');
const credentialBiometricType = document.getElementById('credentialBiometricType');
const credentialFingerIndexGroup = document.getElementById('credentialFingerIndexGroup');
const enrollmentEmployee = document.getElementById('enrollmentEmployee');
const credentialEnrollmentPin = document.getElementById('credentialEnrollmentPin');
const credentialEnrollmentPinAlert = document.getElementById('credentialEnrollmentPinAlert');
const credentialEnrollmentSubmit = document.getElementById('credentialEnrollmentSubmit');

const typeConfig = {
    CARD: {
        label: 'Card Number',
        placeholder: 'Enter card number',
        help: 'Stores the employee card number used in device user sync payloads.',
    },
    PASSCODE: {
        label: 'Passcode',
        placeholder: 'Enter device passcode',
        help: 'Stores the device password field for the employee.',
    },
    COMMON: {
        label: 'Common Code',
        placeholder: 'Enter common code',
        help: 'Stores the employee common code in the vice card field used by sync commands.',
    },
};

function syncCredentialFieldState() {
    const config = typeConfig[credentialType.value] || typeConfig.CARD;
    credentialValueLabel.innerHTML = `${config.label} <span class="text-danger">*</span>`;
    credentialValue.placeholder = config.placeholder;
    credentialValueHelp.textContent = config.help;
}

function syncEnrollmentFieldState() {
    if (credentialBiometricType && credentialFingerIndexGroup) {
        credentialFingerIndexGroup.style.display = credentialBiometricType.value === 'fingerprint' ? '' : 'none';
    }

    if (!enrollmentEmployee || !credentialEnrollmentPin || !credentialEnrollmentPinAlert || !credentialEnrollmentSubmit) {
        return;
    }

    const selectedOption = enrollmentEmployee.options[enrollmentEmployee.selectedIndex];
    const pin = selectedOption ? (selectedOption.dataset.pin || '').trim() : '';

    credentialEnrollmentPin.value = pin;
    credentialEnrollmentPinAlert.classList.toggle('d-none', pin !== '');
    credentialEnrollmentSubmit.disabled = pin === '';
}

credentialType.addEventListener('change', syncCredentialFieldState);
syncCredentialFieldState();

if (credentialBiometricType) {
    credentialBiometricType.addEventListener('change', syncEnrollmentFieldState);
}

if (enrollmentEmployee) {
    enrollmentEmployee.addEventListener('change', syncEnrollmentFieldState);
}

syncEnrollmentFieldState();

window.initBiometricEnrollmentVisualizer?.({
    formSelector: '#credentialBiometricEnrollmentForm',
    typeSelector: '#credentialBiometricType',
    modalId: 'credentialBiometricEnrollmentModal',
});
</script>
@endpush
