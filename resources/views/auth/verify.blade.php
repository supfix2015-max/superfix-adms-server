@extends('layouts.app')

@section('title', 'Verify Email')

@section('content')
    <div class="auth-grid">
        <section class="auth-promo">
            <div class="eyebrow">
                <i class="bi bi-envelope-check"></i>
                Email Verification
            </div>
            <h1>Verify the operator email before entering the enterprise control workspace.</h1>
            <p>Verification adds one more trust signal for privileged administrative access and recovery flows.</p>
        </section>

        <section class="auth-panel">
            <div class="auth-panel-header">
                <h2>Check your inbox</h2>
                <p>Open the verification email we sent and follow the secure link to continue.</p>
            </div>

            @if (session('resent'))
                <div class="alert alert-success">A fresh verification link has been sent to your email address.</div>
            @endif

            <div class="panel-stack">
                <div class="stat-pill">
                    <strong>Email confirmation required</strong>
                    <span>Before proceeding, please check your email for a verification link. If you did not receive the email, request another one below.</span>
                </div>

                <form method="POST" action="{{ route('verification.resend') }}">
                    @csrf
                    <button type="submit" class="btn btn-primary w-100">Resend Verification Email</button>
                </form>
            </div>
        </section>
    </div>
@endsection
