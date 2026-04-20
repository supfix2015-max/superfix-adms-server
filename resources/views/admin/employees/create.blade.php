@extends('admin.layout')

@section('title', 'Create Employee')
@section('page_title', 'Add New Employee')
@section('content')
<div class="row">
    <div class="col-md-8 offset-md-2">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ route('admin.employees.store') }}">
                    @csrf

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Employee ID *</label>
                                <input type="text" class="form-control @error('employee_id') is-invalid @enderror" 
                                       name="employee_id" value="{{ old('employee_id') }}" required maxlength="20">
                                @error('employee_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <option value="active" {{ old('status', 'active') === 'active' ? 'selected' : '' }}>Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="suspended">Suspended</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">First Name *</label>
                                <input type="text" class="form-control @error('first_name') is-invalid @enderror" 
                                       name="first_name" value="{{ old('first_name') }}" required>
                                @error('first_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Last Name *</label>
                                <input type="text" class="form-control @error('last_name') is-invalid @enderror" 
                                       name="last_name" value="{{ old('last_name') }}" required>
                                @error('last_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control @error('email') is-invalid @enderror" 
                                       name="email" value="{{ old('email') }}">
                                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Phone</label>
                                <input type="tel" class="form-control" name="phone" value="{{ old('phone') }}">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Device UID</label>
                                <input type="text" class="form-control @error('uid') is-invalid @enderror"
                                       name="uid" value="{{ old('uid') }}" inputmode="numeric">
                                @error('uid')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Card Number</label>
                                <input type="text" class="form-control @error('card_no') is-invalid @enderror"
                                       name="card_no" value="{{ old('card_no') }}">
                                @error('card_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Device Password</label>
                                <input type="text" class="form-control @error('device_password') is-invalid @enderror"
                                       name="device_password" value="{{ old('device_password') }}">
                                @error('device_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Privilege</label>
                                <input type="number" class="form-control @error('device_privilege') is-invalid @enderror"
                                       name="device_privilege" value="{{ old('device_privilege', 0) }}" min="0" max="14">
                                @error('device_privilege')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Group Code</label>
                                <input type="text" class="form-control @error('group_code') is-invalid @enderror"
                                       name="group_code" value="{{ old('group_code') }}">
                                @error('group_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Timezone Code</label>
                                <input type="text" class="form-control @error('timezone_code') is-invalid @enderror"
                                       name="timezone_code" value="{{ old('timezone_code') }}">
                                @error('timezone_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Verify Mode</label>
                                <input type="text" class="form-control @error('verification_mode') is-invalid @enderror"
                                       name="verification_mode" value="{{ old('verification_mode') }}">
                                @error('verification_mode')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Vice Card</label>
                                <input type="text" class="form-control @error('vice_card_no') is-invalid @enderror"
                                       name="vice_card_no" value="{{ old('vice_card_no') }}">
                                @error('vice_card_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Hire Date</label>
                                <input type="date" class="form-control" name="hire_date" value="{{ old('hire_date') }}">
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Department</label>
                        <input type="text" class="form-control" name="department" value="{{ old('department') }}" 
                               placeholder="Engineering, HR, Sales, etc.">
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Valid From</label>
                                <input type="date" class="form-control @error('valid_from') is-invalid @enderror"
                                       name="valid_from" value="{{ old('valid_from') }}">
                                @error('valid_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Valid Until</label>
                                <input type="date" class="form-control @error('valid_until') is-invalid @enderror"
                                       name="valid_until" value="{{ old('valid_until') }}">
                                @error('valid_until')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="3" placeholder="Any additional information...">{{ old('notes') }}</textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-person-plus"></i> Create Employee
                        </button>
                        <a href="{{ route('admin.employees.index') }}" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection
