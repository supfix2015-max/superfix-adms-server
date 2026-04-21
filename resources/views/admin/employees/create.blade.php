@extends('admin.layout')

@section('title', 'Create Employee')
@section('page_title', 'Add New Employee')
@section('page_subtitle', 'Create a workforce profile that can be synced to devices, assigned credentials, and used for attendance reconciliation.')

@section('page_actions')
    <a href="{{ route('admin.employees.index') }}" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>
        Back To Employees
    </a>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.employees.store') }}">
        @csrf

        <div class="row g-4">
            <div class="col-xl-8">
                <div class="card shell-card">
                    <div class="card-header">
                        <div>
                            <h5 class="shell-title">Employee Identity</h5>
                            <div class="shell-subtitle">Primary directory fields used across the admin workspace and device sync payloads.</div>
                        </div>
                    </div>
                    <div class="card-body panel-stack">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="employee_id" class="form-label fw-semibold">Employee ID</label>
                                <input id="employee_id" type="text" class="form-control @error('employee_id') is-invalid @enderror" name="employee_id" value="{{ old('employee_id') }}" maxlength="20" required>
                                @error('employee_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label for="status" class="form-label fw-semibold">Status</label>
                                <select id="status" class="form-select" name="status">
                                    <option value="active" {{ old('status', 'active') === 'active' ? 'selected' : '' }}>Active</option>
                                    <option value="inactive" {{ old('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                                    <option value="suspended" {{ old('status') === 'suspended' ? 'selected' : '' }}>Suspended</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="first_name" class="form-label fw-semibold">First Name</label>
                                <input id="first_name" type="text" class="form-control @error('first_name') is-invalid @enderror" name="first_name" value="{{ old('first_name') }}" required>
                                @error('first_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label for="last_name" class="form-label fw-semibold">Last Name</label>
                                <input id="last_name" type="text" class="form-control @error('last_name') is-invalid @enderror" name="last_name" value="{{ old('last_name') }}" required>
                                @error('last_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label for="email" class="form-label fw-semibold">Email</label>
                                <input id="email" type="email" class="form-control @error('email') is-invalid @enderror" name="email" value="{{ old('email') }}">
                                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label for="phone" class="form-label fw-semibold">Phone</label>
                                <input id="phone" type="tel" class="form-control" name="phone" value="{{ old('phone') }}">
                            </div>
                            <div class="col-md-8">
                                <label for="department" class="form-label fw-semibold">Department</label>
                                <input id="department" type="text" class="form-control" name="department" value="{{ old('department') }}" placeholder="Engineering, Operations, HR">
                            </div>
                            <div class="col-md-4">
                                <label for="hire_date" class="form-label fw-semibold">Hire Date</label>
                                <input id="hire_date" type="date" class="form-control" name="hire_date" value="{{ old('hire_date') }}">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shell-card">
                    <div class="card-header">
                        <div>
                            <h5 class="shell-title">Device Sync Profile</h5>
                            <div class="shell-subtitle">Optional fields used by ZKTeco sync, credentials, and terminal-side access settings.</div>
                        </div>
                    </div>
                    <div class="card-body panel-stack">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="uid" class="form-label fw-semibold">Device UID</label>
                                <input id="uid" type="text" class="form-control @error('uid') is-invalid @enderror" name="uid" value="{{ old('uid') }}" inputmode="numeric">
                                @error('uid')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label for="card_no" class="form-label fw-semibold">Card Number</label>
                                <input id="card_no" type="text" class="form-control @error('card_no') is-invalid @enderror" name="card_no" value="{{ old('card_no') }}">
                                @error('card_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label for="device_password" class="form-label fw-semibold">Device Password</label>
                                <input id="device_password" type="text" class="form-control @error('device_password') is-invalid @enderror" name="device_password" value="{{ old('device_password') }}">
                                @error('device_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label for="device_privilege" class="form-label fw-semibold">Privilege</label>
                                <input id="device_privilege" type="number" class="form-control @error('device_privilege') is-invalid @enderror" name="device_privilege" value="{{ old('device_privilege', 0) }}" min="0" max="14">
                                @error('device_privilege')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label for="group_code" class="form-label fw-semibold">Group Code</label>
                                <input id="group_code" type="text" class="form-control @error('group_code') is-invalid @enderror" name="group_code" value="{{ old('group_code') }}">
                                @error('group_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label for="timezone_code" class="form-label fw-semibold">Timezone Code</label>
                                <input id="timezone_code" type="text" class="form-control @error('timezone_code') is-invalid @enderror" name="timezone_code" value="{{ old('timezone_code') }}">
                                @error('timezone_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label for="verification_mode" class="form-label fw-semibold">Verify Mode</label>
                                <input id="verification_mode" type="text" class="form-control @error('verification_mode') is-invalid @enderror" name="verification_mode" value="{{ old('verification_mode') }}">
                                @error('verification_mode')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label for="vice_card_no" class="form-label fw-semibold">Vice Card</label>
                                <input id="vice_card_no" type="text" class="form-control @error('vice_card_no') is-invalid @enderror" name="vice_card_no" value="{{ old('vice_card_no') }}">
                                @error('vice_card_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label for="valid_from" class="form-label fw-semibold">Valid From</label>
                                <input id="valid_from" type="date" class="form-control @error('valid_from') is-invalid @enderror" name="valid_from" value="{{ old('valid_from') }}">
                                @error('valid_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label for="valid_until" class="form-label fw-semibold">Valid Until</label>
                                <input id="valid_until" type="date" class="form-control @error('valid_until') is-invalid @enderror" name="valid_until" value="{{ old('valid_until') }}">
                                @error('valid_until')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-12">
                                <label for="notes" class="form-label fw-semibold">Notes</label>
                                <textarea id="notes" class="form-control" name="notes" rows="4" placeholder="Optional operational notes or enrollment context...">{{ old('notes') }}</textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card shell-card">
                    <div class="card-header">
                        <div>
                            <h5 class="shell-title">Directory Guidance</h5>
                            <div class="shell-subtitle">Use these fields deliberately so sync and enrollment workflows stay predictable.</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="stat-stack">
                            <div class="stat-pill">
                                <strong>Employee ID is the primary key</strong>
                                <span>Keep it stable. This value is used across attendance records, device sync payloads, and biometric replay workflows.</span>
                            </div>
                            <div class="stat-pill">
                                <strong>Device UID should be numeric</strong>
                                <span>Use a numeric UID when the terminal model expects one for biometric enrollment or credential sync.</span>
                            </div>
                            <div class="stat-pill">
                                <strong>Credentials can be added later</strong>
                                <span>Card numbers, passcodes, and common codes can also be managed from the dedicated credentials workspace.</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shell-card">
                    <div class="card-body">
                        <div class="toolbar-cluster">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-person-plus me-1"></i>
                                Create Employee
                            </button>
                            <a href="{{ route('admin.employees.index') }}" class="btn btn-outline-secondary w-100">Cancel</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection
