@extends('layouts.app')

@section('title', 'Register')

@section('content')
    <div class="auth-grid">
        <section class="auth-promo">
            <div class="eyebrow">
                <i class="bi bi-building-gear"></i>
                Team Provisioning
            </div>
            <h1>Create a new operator account for the ADMS workspace.</h1>
            <p>Provision administrative access for the team members responsible for devices, attendance reconciliation, and biometric operations.</p>

            <div class="auth-promo-points">
                <div class="auth-promo-point">
                    <strong>Controlled Access</strong>
                    <span>Keep device operations and identity management inside a dedicated admin environment.</span>
                </div>
                <div class="auth-promo-point">
                    <strong>Fast Handover</strong>
                    <span>Onboard operators quickly with a secure account creation flow and role-based workspace access.</span>
                </div>
            </div>
        </section>

        <section class="auth-panel">
            <div class="auth-panel-header">
                <h2>Create account</h2>
                <p>Register a new administrator or operator profile.</p>
            </div>

            <form method="POST" action="{{ route('register') }}" class="panel-stack">
                @csrf

                <div>
                    <label for="name" class="form-label fw-semibold">Full Name</label>
                    <input id="name" type="text" class="form-control @error('name') is-invalid @enderror" name="name" value="{{ old('name') }}" required autocomplete="name" autofocus>
                    @error('name')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="email" class="form-label fw-semibold">Email Address</label>
                    <input id="email" type="email" class="form-control @error('email') is-invalid @enderror" name="email" value="{{ old('email') }}" required autocomplete="email">
                    @error('email')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="password" class="form-label fw-semibold">Password</label>
                    <input id="password" type="password" class="form-control @error('password') is-invalid @enderror" name="password" required autocomplete="new-password">
                    @error('password')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="password-confirm" class="form-label fw-semibold">Confirm Password</label>
                    <input id="password-confirm" type="password" class="form-control" name="password_confirmation" required autocomplete="new-password">
                </div>

                <button type="submit" class="btn btn-primary w-100">Create Account</button>

                <div class="small text-center">
                    Already have an account?
                    <a href="{{ route('login') }}" class="fw-semibold">Sign in</a>
                </div>
            </form>
        </section>
    </div>
@endsection
