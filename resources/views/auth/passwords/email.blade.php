@extends('layouts.app')

@section('title', 'Reset Password')

@section('content')
    <div class="auth-grid">
        <section class="auth-promo">
            <div class="eyebrow">
                <i class="bi bi-envelope-paper"></i>
                Recovery Flow
            </div>
            <h1>Recover workspace access securely.</h1>
            <p>We will send a password reset link to the operator email address associated with your ADMS account.</p>
        </section>

        <section class="auth-panel">
            <div class="auth-panel-header">
                <h2>Request reset link</h2>
                <p>Enter your email address to receive password recovery instructions.</p>
            </div>

            @if (session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif

            <form method="POST" action="{{ route('password.email') }}" class="panel-stack">
                @csrf

                <div>
                    <label for="email" class="form-label fw-semibold">Email Address</label>
                    <input id="email" type="email" class="form-control @error('email') is-invalid @enderror" name="email" value="{{ old('email') }}" required autocomplete="email" autofocus>
                    @error('email')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <button type="submit" class="btn btn-primary w-100">Send Password Reset Link</button>
            </form>
        </section>
    </div>
@endsection
