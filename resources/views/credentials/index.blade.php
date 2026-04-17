@extends('zlink.layout')
@section('title', 'Credentials')
@section('page-title', 'Credentials')

@section('content')
@if($error)
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-2"></i>
        Could not load credentials from ZLink: {{ $error }}
    </div>
@endif

<div class="row g-3">
    {{-- Add Credential --}}
    <div class="col-xl-4">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-plus-circle me-2 text-success"></i>Add Credential</h6>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('zlink.credentials.store') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fw-medium">Employee <span class="text-danger">*</span></label>
                        <select name="employee_id" class="form-select @error('employee_id') is-invalid @enderror" required>
                            <option value="">— Select Employee —</option>
                            @foreach($employees as $emp)
                                <option value="{{ $emp->zlink_id }}" {{ old('employee_id') === $emp->zlink_id ? 'selected' : '' }}>
                                    {{ $emp->full_name }} ({{ $emp->employee_number ?? $emp->zlink_id }})
                                </option>
                            @endforeach
                        </select>
                        @error('employee_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Type <span class="text-danger">*</span></label>
                        <select name="type" class="form-select" id="credType" required>
                            <option value="CARD" {{ old('type') === 'CARD' ? 'selected' : '' }}>Card (RF/IC)</option>
                            <option value="PASSCODE" {{ old('type') === 'PASSCODE' ? 'selected' : '' }}>Passcode</option>
                            <option value="COMMON" {{ old('type') === 'COMMON' ? 'selected' : '' }}>Common</option>
                        </select>
                    </div>
                    <div class="mb-3" id="cardNumberGroup">
                        <label class="form-label fw-medium">Card Number</label>
                        <input type="text" name="card_number" class="form-control @error('card_number') is-invalid @enderror"
                               value="{{ old('card_number') }}" placeholder="e.g. 1234567890">
                        @error('card_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-medium">Effective Date</label>
                            <input type="date" name="effective_date" class="form-control" value="{{ old('effective_date', now()->toDateString()) }}">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-medium">Expiration Date</label>
                            <input type="date" name="expiration_date" class="form-control" value="{{ old('expiration_date', now()->addYear()->toDateString()) }}">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-success w-100">
                        <i class="bi bi-plus me-1"></i> Add Credential
                    </button>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-person-x me-2 text-warning"></i>No-Credential Employees</h6>
            </div>
            <div class="card-body">
                <p class="text-muted small">Employees who have no credentials assigned.</p>
                <a href="{{ route('zlink.credentials.no-credential') }}" class="btn btn-outline-warning btn-sm">
                    <i class="bi bi-search me-1"></i> View List
                </a>
            </div>
        </div>
    </div>

    {{-- Credential List --}}
    <div class="col-xl-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-semibold">
                    <i class="bi bi-credit-card-2-front me-2 text-primary"></i>
                    Credentials ({{ count($credentials) }})
                </h6>
            </div>
            <div class="card-body p-0">
                @if(empty($credentials))
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-credit-card fs-1 d-block mb-3 opacity-25"></i>
                        No credentials loaded from ZLink.
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Employee</th>
                                    <th>Type</th>
                                    <th>Card Number</th>
                                    <th>Effective</th>
                                    <th>Expires</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($credentials as $cred)
                                <tr>
                                    <td>
                                        <div class="fw-medium small">{{ $cred['employeeName'] ?? $cred['personName'] ?? '—' }}</div>
                                        <div class="text-muted" style="font-size:.75rem">{{ $cred['employeeNo'] ?? '' }}</div>
                                    </td>
                                    <td>
                                        @php $t = $cred['type'] ?? 'CARD'; @endphp
                                        <span class="badge bg-{{ $t === 'FACE' ? 'info' : ($t === 'CARD' ? 'primary' : ($t === 'QR' ? 'warning text-dark' : 'secondary')) }} badge-pill">{{ $t }}</span>
                                    </td>
                                    <td class="font-monospace small">{{ $cred['cardNumber'] ?? '—' }}</td>
                                    <td class="text-muted small">{{ $cred['effectiveDate'] ?? '—' }}</td>
                                    <td class="text-muted small">{{ $cred['expirationDate'] ?? '—' }}</td>
                                    <td>
                                        @php $status = $cred['status'] ?? 'ACTIVE'; @endphp
                                        <span class="badge bg-{{ $status === 'ACTIVE' ? 'success' : 'secondary' }} badge-pill">{{ $status }}</span>
                                    </td>
                                    <td>
                                        <form method="POST" action="{{ route('zlink.credentials.destroy', $cred['id'] ?? $cred['credentialId']) }}"
                                              onsubmit="return confirm('Delete this credential?')">
                                            @csrf @method('DELETE')
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
    </div>
</div>
@endsection

@push('scripts')
<script>
const credType = document.getElementById('credType');
const cardGroup = document.getElementById('cardNumberGroup');
credType.addEventListener('change', () => {
    cardGroup.style.display = credType.value === 'CARD' ? '' : 'none';
});
credType.dispatchEvent(new Event('change'));
</script>
@endpush
