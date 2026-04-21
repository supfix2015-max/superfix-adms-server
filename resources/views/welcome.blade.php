@extends('layouts.app')

@section('title', 'ADMS Enterprise')

@section('content')
    <div class="card mb-4">
        <div class="card-body py-5">
            <div class="row g-4 align-items-center">
                <div class="col-xl-7">
                    <span class="eyebrow text-primary fw-bold text-uppercase small">ADMS Enterprise</span>
                    <h1 class="mt-2 mb-3">Modern biometric operations for devices, employee sync, and attendance visibility.</h1>
                    <p class="text-muted mb-4">Manage your ZKTeco-connected environment from one enterprise surface built for enrollment, replay, diagnostics, and workforce audit trails.</p>
                    <div class="toolbar-cluster">
                        @auth
                            <a href="{{ route('admin.dashboard') }}" class="btn btn-primary">
                                <i class="bi bi-grid-1x2 me-1"></i>
                                Open Admin Workspace
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="btn btn-primary">
                                <i class="bi bi-box-arrow-in-right me-1"></i>
                                Sign In
                            </a>
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="btn btn-outline-secondary">
                                    <i class="bi bi-person-plus me-1"></i>
                                    Create Account
                                </a>
                            @endif
                        @endauth
                    </div>
                </div>

                <div class="col-xl-5">
                    <div class="stat-stack">
                        <div class="stat-pill">
                            <strong>Device fleet control</strong>
                            <span>Track heartbeats, queue commands, rotate tokens, and review terminal health from the control plane.</span>
                        </div>
                        <div class="stat-pill">
                            <strong>Biometric enrollment and replay</strong>
                            <span>Capture templates from one device and push stored biometrics to other approved terminals.</span>
                        </div>
                        <div class="stat-pill">
                            <strong>Attendance and diagnostics</strong>
                            <span>Investigate raw payloads, unmatched records, and historical activity without leaving the workspace.</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="metric-grid">
        <div class="card metric-card">
            <div class="card-body">
                <div class="metric-eyebrow">Control</div>
                <div class="metric-value">Devices</div>
                <div class="metric-note"><i class="bi bi-hdd-network"></i> live terminal visibility</div>
            </div>
        </div>
        <div class="card metric-card">
            <div class="card-body">
                <div class="metric-eyebrow">Identity</div>
                <div class="metric-value">Employees</div>
                <div class="metric-note"><i class="bi bi-people"></i> synced workforce records</div>
            </div>
        </div>
        <div class="card metric-card">
            <div class="card-body">
                <div class="metric-eyebrow">Security</div>
                <div class="metric-value">Biometrics</div>
                <div class="metric-note"><i class="bi bi-fingerprint"></i> enrollment and replay</div>
            </div>
        </div>
        <div class="card metric-card">
            <div class="card-body">
                <div class="metric-eyebrow">Audit</div>
                <div class="metric-value">Logs</div>
                <div class="metric-note"><i class="bi bi-journal-text"></i> raw traffic and errors</div>
            </div>
        </div>
    </div>
@endsection
