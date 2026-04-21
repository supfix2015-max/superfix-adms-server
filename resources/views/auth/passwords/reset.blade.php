@extends('layouts.app')

@section('title', 'Reset Password')

@section('content')
    <div class="auth-grid">
        <section class="auth-promo">
            <div class="eyebrow">
                <i class="bi bi-arrow-repeat"></i>
                Password Recovery
            </div>
            <h1>Complete the credential reset and return to the enterprise workspace.</h1>
            <p>Use a strong replacement password so administrative access to devices, biometrics, and attendance data stays protected.</p>
        </section>

        <section class="auth-panel">
            <div class="auth-panel-header">
                <h2>Reset password</h2>
                <p>Enter the recovery token details below and set a new operator password.</p>
            </div>

            <form method="POST" action="{{ route('password.update') }}" class="panel-stack">
                @csrf

                <input type="hidden" name="token" value="{{ $token }}">

                <div>
                    <label for="email" class="form-label fw-semibold">Email Address</label>
                    <input id="email" type="email" class="form-control @error('email') is-invalid @enderror" name="email" value="{{ $email ?? old('email') }}" required autocomplete="email" autofocus>
                    @error('email')
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
                    <label for="password-confirm" class="form-label fw-semibold">Confirm Password</label>
                    <input id="password-confirm" type="password" class="form-control" name="password_confirmation" required autocomplete="new-password">
                </div>

                <button type="submit" class="btn btn-primary w-100">Reset Password</button>
            </form>
        </section>
    </div>
@endsection
