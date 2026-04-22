@extends('admin.layout')

@section('title', 'Edit Employee')
@section('page_title', 'Edit Employee Profile')
@section('page_subtitle', 'Update directory, sync, and access-control fields without losing attendance history or stored biometric associations.')

@section('page_actions')
    <a href="{{ route('admin.employees.show', $employee) }}" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>
        Back To Employee
    </a>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.employees.update', $employee) }}">
        @csrf
        @method('PUT')

        <div class="row g-4">
            <div class="col-xl-8">
                <div class="card shell-card">
                    <div class="card-header">
                        <div>
                            <h5 class="shell-title">Employee Identity</h5>
                            <div class="shell-subtitle">Edit the employee directory record used throughout attendance, credentials, and device sync operations.</div>
                        </div>
                    </div>
                    <div class="card-body panel-stack">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="employee_id_display" class="form-label fw-semibold">Employee ID</label>
                                <input id="employee_id_display" type="text" class="form-control bg-light" value="{{ $employee->employee_id ?? 'ARCHIVED' }}" readonly>
                                <div class="form-text">Employee ID is released after offboarding so the next joiner can reuse it.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="status" class="form-label fw-semibold">Status</label>
                                <select id="status" class="form-select @error('status') is-invalid @enderror" name="status">
                                    @foreach(\App\Models\Employee::statusOptions() as $status)
                                        <option value="{{ $status }}" {{ \App\Models\Employee::normalizeStatus(old('status', $employee->status)) === $status ? 'selected' : '' }}>
                                            {{ \App\Models\Employee::labelForStatus($status) }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label for="first_name" class="form-label fw-semibold">First Name</label>
                                <input id="first_name" type="text" class="form-control @error('first_name') is-invalid @enderror" name="first_name" value="{{ old('first_name', $employee->first_name) }}" required>
                                @error('first_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label for="last_name" class="form-label fw-semibold">Last Name</label>
                                <input id="last_name" type="text" class="form-control @error('last_name') is-invalid @enderror" name="last_name" value="{{ old('last_name', $employee->last_name) }}" required>
                                @error('last_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label for="email" class="form-label fw-semibold">Email</label>
                                <input id="email" type="email" class="form-control @error('email') is-invalid @enderror" name="email" value="{{ old('email', $employee->email) }}">
                                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label for="phone" class="form-label fw-semibold">Phone</label>
                                <input id="phone" type="tel" class="form-control" name="phone" value="{{ old('phone', $employee->phone) }}">
                            </div>
                            <div class="col-md-8">
                                <label for="department" class="form-label fw-semibold">Department</label>
                                <input id="department" type="text" class="form-control" name="department" value="{{ old('department', $employee->department) }}">
                            </div>
                            <div class="col-md-4">
                                <label for="hire_date" class="form-label fw-semibold">Hire Date</label>
                                <input id="hire_date" type="date" class="form-control" name="hire_date" value="{{ old('hire_date', $employee->hire_date ? \Carbon\Carbon::parse($employee->hire_date)->format('Y-m-d') : '') }}">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shell-card">
                    <div class="card-header">
                        <div>
                            <h5 class="shell-title">Device Sync Profile</h5>
                            <div class="shell-subtitle">Maintain access-control and device-side fields used by ADMS replay and sync flows.</div>
                        </div>
                    </div>
                    <div class="card-body panel-stack">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="uid" class="form-label fw-semibold">Device UID</label>
                                <input id="uid" type="text" class="form-control @error('uid') is-invalid @enderror" name="uid" value="{{ old('uid', $employee->uid) }}" inputmode="numeric">
                                @error('uid')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label for="card_no" class="form-label fw-semibold">Card Number</label>
                                <input id="card_no" type="text" class="form-control @error('card_no') is-invalid @enderror" name="card_no" value="{{ old('card_no', $employee->card_no) }}">
                                @error('card_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label for="device_password" class="form-label fw-semibold">Device Password</label>
                                <input id="device_password" type="text" class="form-control @error('device_password') is-invalid @enderror" name="device_password" value="{{ old('device_password', $employee->device_password) }}">
                                @error('device_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label for="device_privilege" class="form-label fw-semibold">Privilege</label>
                                <input id="device_privilege" type="number" class="form-control @error('device_privilege') is-invalid @enderror" name="device_privilege" value="{{ old('device_privilege', $employee->device_privilege ?? 0) }}" min="0" max="14">
                                @error('device_privilege')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label for="group_code" class="form-label fw-semibold">Group Code</label>
                                <input id="group_code" type="text" class="form-control @error('group_code') is-invalid @enderror" name="group_code" value="{{ old('group_code', $employee->group_code) }}">
                                @error('group_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label for="timezone_code" class="form-label fw-semibold">Timezone Code</label>
                                <input id="timezone_code" type="text" class="form-control @error('timezone_code') is-invalid @enderror" name="timezone_code" value="{{ old('timezone_code', $employee->timezone_code) }}">
                                @error('timezone_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label for="verification_mode" class="form-label fw-semibold">Verify Mode</label>
                                <input id="verification_mode" type="text" class="form-control @error('verification_mode') is-invalid @enderror" name="verification_mode" value="{{ old('verification_mode', $employee->verification_mode) }}">
                                @error('verification_mode')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label for="vice_card_no" class="form-label fw-semibold">Vice Card</label>
                                <input id="vice_card_no" type="text" class="form-control @error('vice_card_no') is-invalid @enderror" name="vice_card_no" value="{{ old('vice_card_no', $employee->vice_card_no) }}">
                                @error('vice_card_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label for="valid_from" class="form-label fw-semibold">Valid From</label>
                                <input id="valid_from" type="date" class="form-control @error('valid_from') is-invalid @enderror" name="valid_from" value="{{ old('valid_from', $employee->valid_from?->format('Y-m-d')) }}">
                                @error('valid_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label for="valid_until" class="form-label fw-semibold">Valid Until</label>
                                <input id="valid_until" type="date" class="form-control @error('valid_until') is-invalid @enderror" name="valid_until" value="{{ old('valid_until', $employee->valid_until?->format('Y-m-d')) }}">
                                @error('valid_until')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-12">
                                <label for="notes" class="form-label fw-semibold">Notes</label>
                                <textarea id="notes" class="form-control" name="notes" rows="4">{{ old('notes', $employee->notes) }}</textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card shell-card">
                    <div class="card-header">
                        <div>
                            <h5 class="shell-title">Current Record State</h5>
                            <div class="shell-subtitle">Context that helps before committing directory changes.</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="stat-stack">
                            <div class="stat-pill">
                                <strong>{{ $employee->full_name }}</strong>
                                <span>Primary display name shown in the employee directory and audit screens.</span>
                            </div>
                            <div class="stat-pill">
                                <strong>{{ $employee->status_label }}</strong>
                                <span>Changing the employee status affects how the record appears across filters and sync scopes.</span>
                            </div>
                            <div class="stat-pill">
                                <strong>{{ $employee->uid ?: 'No device UID set' }}</strong>
                                <span>Numeric UIDs improve compatibility for terminal enrollment and direct device replay workflows.</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shell-card">
                    <div class="card-body">
                        <div class="toolbar-cluster">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-save me-1"></i>
                                Save Employee
                            </button>
                            <a href="{{ route('admin.employees.show', $employee) }}" class="btn btn-outline-secondary w-100">Cancel</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection
