@extends('layouts.app')

@section('title', 'Login')

@section('content')
    <div class="auth-grid">
        <section class="auth-promo">
            <div class="eyebrow">
                <i class="bi bi-shield-lock"></i>
                Secure Access
            </div>
            <h1>Operate every biometric workflow from one enterprise console.</h1>
            <p>Access the ADMS workspace to manage terminals, employee identities, credential enrollment, biometric replay, and attendance operations.</p>

            <div class="auth-promo-points">
                <div class="auth-promo-point">
                    <strong>Device Operations</strong>
                    <span>Monitor polling terminals, token state, heartbeat, and command execution from a unified fleet view.</span>
                </div>
                <div class="auth-promo-point">
                    <strong>Identity Control</strong>
                    <span>Manage employee profiles, credentials, and biometric enrollment without leaving the workspace.</span>
                </div>
                <div class="auth-promo-point">
                    <strong>Operational Audit</strong>
                    <span>Review raw ADMS callbacks, attendance anomalies, and command history with full traceability.</span>
                </div>
            </div>
        </section>

        <section class="auth-panel">
            <div class="auth-panel-header">
                <h2>Welcome back</h2>
                <p>Sign in to continue into the ADMS enterprise workspace.</p>
            </div>

            <form method="POST" action="{{ route('login') }}" class="panel-stack">
                @csrf

                <div>
                    <label for="email" class="form-label fw-semibold">Email Address</label>
                    <input id="email" type="email" class="form-control @error('email') is-invalid @enderror" name="email" value="{{ old('email') }}" required autocomplete="email" autofocus>
                    @error('email')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="password" class="form-label fw-semibold">Password</label>
                    <input id="password" type="password" class="form-control @error('password') is-invalid @enderror" name="password" required autocomplete="current-password">
                    @error('password')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="remember" id="remember" {{ old('remember') ? 'checked' : '' }}>
                        <label class="form-check-label" for="remember">Remember me</label>
                    </div>
                    @if (Route::has('password.request'))
                        <a href="{{ route('password.request') }}" class="small fw-semibold">Forgot password?</a>
                    @endif
                </div>

                <button type="submit" class="btn btn-primary w-100">Sign In</button>

                @if (Route::has('register'))
                    <div class="small text-center">
                        Need an operator account?
                        <a href="{{ route('register') }}" class="fw-semibold">Create one</a>
                    </div>
                @endif
            </form>
        </section>
    </div>
@endsection
