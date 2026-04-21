@extends('layouts.app')

@section('title', 'Change Password')

@section('content')
    <div class="auth-grid">
        <section class="auth-promo">
            <div class="eyebrow">
                <i class="bi bi-key"></i>
                Credential Security
            </div>
            <h1>Rotate your operator password without leaving the secure workspace.</h1>
            <p>Maintain access hygiene for the team managing device operations and biometric workflows.</p>
        </section>

        <section class="auth-panel">
            <div class="auth-panel-header">
                <h2>Change password</h2>
                <p>Use a strong password to protect enterprise device access.</p>
            </div>

            <form method="POST" action="{{ route('admin-password.update') }}" class="panel-stack">
                @csrf

                <div>
                    <label for="current_password" class="form-label fw-semibold">Current Password</label>
                    <input id="current_password" type="password" class="form-control @error('current_password') is-invalid @enderror" name="current_password" required autocomplete="current-password">
                    @error('current_password')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="password" class="form-label fw-semibold">New Password</label>
                    <input id="password" type="password" class="form-control @error('password') is-invalid @enderror" name="password" required autocomplete="new-password">
                    @error('password')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="password-confirm" class="form-label fw-semibold">Confirm New Password</label>
                    <input id="password-confirm" type="password" class="form-control" name="password_confirmation" required autocomplete="new-password">
                </div>

                <button type="submit" class="btn btn-primary w-100">Change Password</button>
            </form>
        </section>
    </div>
@endsection
