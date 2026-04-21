@extends('layouts.app')

@section('title', 'Confirm Password')

@section('content')
    <div class="auth-grid">
        <section class="auth-promo">
            <div class="eyebrow">
                <i class="bi bi-shield-check"></i>
                Verification Step
            </div>
            <h1>Confirm your credentials before continuing with a sensitive action.</h1>
            <p>This extra confirmation protects privileged administrative operations in the ADMS environment.</p>
        </section>

        <section class="auth-panel">
            <div class="auth-panel-header">
                <h2>Confirm password</h2>
                <p>Please re-enter your password before continuing.</p>
            </div>

            <form method="POST" action="{{ route('password.confirm') }}" class="panel-stack">
                @csrf

                <div>
                    <label for="password" class="form-label fw-semibold">Password</label>
                    <input id="password" type="password" class="form-control @error('password') is-invalid @enderror" name="password" required autocomplete="current-password">
                    @error('password')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <button type="submit" class="btn btn-primary w-100">Confirm Password</button>

                @if (Route::has('password.request'))
                    <div class="small text-center">
                        Forgot your password?
                        <a href="{{ route('password.request') }}" class="fw-semibold">Reset access</a>
                    </div>
                @endif
            </form>
        </section>
    </div>
@endsection
