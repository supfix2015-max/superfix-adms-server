@extends('admin.layout')

@section('title', 'Edit Employee')
@section('page_title', "Edit: {$employee->full_name}")
@section('content')
<div class="row">
    <div class="col-md-8 offset-md-2">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ route('admin.employees.update', $employee) }}">
                    @csrf @method('PUT')

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Employee ID</label>
                                <input type="text" class="form-control bg-light" 
                                       value="{{ $employee->employee_id }}" readonly>
                                <div class="form-text">Cannot be changed after creation</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Status *</label>
                                <select class="form-select @error('status') is-invalid @enderror" name="status">
                                    <option value="active" {{ old('status', $employee->status) === 'active' ? 'selected' : '' }}>Active</option>
                                    <option value="inactive" {{ old('status', $employee->status) === 'inactive' ? 'selected' : '' }}>Inactive</option>
                                    <option value="suspended" {{ old('status', $employee->status) === 'suspended' ? 'selected' : '' }}>Suspended</option>
                                </select>
                                @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">First Name *</label>
                                <input type="text" class="form-control @error('first_name') is-invalid @enderror" 
                                       name="first_name" value="{{ old('first_name', $employee->first_name) }}" required>
                                @error('first_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Last Name *</label>
                                <input type="text" class="form-control @error('last_name') is-invalid @enderror" 
                                       name="last_name" value="{{ old('last_name', $employee->last_name) }}" required>
                                @error('last_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control @error('email') is-invalid @enderror" 
                                       name="email" value="{{ old('email', $employee->email) }}">
                                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Phone</label>
                                <input type="tel" class="form-control" name="phone" value="{{ old('phone', $employee->phone) }}">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Device UID</label>
                                <input type="text" class="form-control @error('uid') is-invalid @enderror"
                                       name="uid" value="{{ old('uid', $employee->uid) }}" inputmode="numeric">
                                @error('uid')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Card Number</label>
                                <input type="text" class="form-control @error('card_no') is-invalid @enderror"
                                       name="card_no" value="{{ old('card_no', $employee->card_no) }}">
                                @error('card_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Device Password</label>
                                <input type="text" class="form-control @error('device_password') is-invalid @enderror"
                                       name="device_password" value="{{ old('device_password', $employee->device_password) }}">
                                @error('device_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Privilege</label>
                                <input type="number" class="form-control @error('device_privilege') is-invalid @enderror"
                                       name="device_privilege" value="{{ old('device_privilege', $employee->device_privilege ?? 0) }}" min="0" max="14">
                                @error('device_privilege')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Group Code</label>
                                <input type="text" class="form-control @error('group_code') is-invalid @enderror"
                                       name="group_code" value="{{ old('group_code', $employee->group_code) }}">
                                @error('group_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Timezone Code</label>
                                <input type="text" class="form-control @error('timezone_code') is-invalid @enderror"
                                       name="timezone_code" value="{{ old('timezone_code', $employee->timezone_code) }}">
                                @error('timezone_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Verify Mode</label>
                                <input type="text" class="form-control @error('verification_mode') is-invalid @enderror"
                                       name="verification_mode" value="{{ old('verification_mode', $employee->verification_mode) }}">
                                @error('verification_mode')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Vice Card</label>
                                <input type="text" class="form-control @error('vice_card_no') is-invalid @enderror"
                                       name="vice_card_no" value="{{ old('vice_card_no', $employee->vice_card_no) }}">
                                @error('vice_card_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Hire Date</label>
                                <input type="date" class="form-control" name="hire_date" value="{{ old('hire_date', $employee->hire_date ? \Carbon\Carbon::parse($employee->hire_date)->format('Y-m-d') : '') }}">
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Department</label>
                        <input type="text" class="form-control" name="department" value="{{ old('department', $employee->department) }}">
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Valid From</label>
                                <input type="date" class="form-control @error('valid_from') is-invalid @enderror"
                                       name="valid_from" value="{{ old('valid_from', $employee->valid_from?->format('Y-m-d')) }}">
                                @error('valid_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Valid Until</label>
                                <input type="date" class="form-control @error('valid_until') is-invalid @enderror"
                                       name="valid_until" value="{{ old('valid_until', $employee->valid_until?->format('Y-m-d')) }}">
                                @error('valid_until')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="3">{{ old('notes', $employee->notes) }}</textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Update Employee</button>
                        <a href="{{ route('admin.employees.show', $employee) }}" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection
