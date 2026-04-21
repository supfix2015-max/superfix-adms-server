@extends('layouts.app')

@section('title', 'Workspace Home')

@section('content')
    <div class="card mb-4">
        <div class="card-body">
            <span class="eyebrow text-primary fw-bold text-uppercase small">Workspace Home</span>
            <h1 class="mt-2 mb-2">Welcome back, {{ auth()->user()->name ?? 'Operator' }}</h1>
            <p class="text-muted mb-0">Use the enterprise workspace to monitor devices, manage employees, review attendance activity, and control biometric replay workflows.</p>
        </div>
    </div>

    <div class="metric-grid">
        <div class="card metric-card">
            <div class="card-body">
                <div class="metric-eyebrow">Devices</div>
                <div class="metric-value">Console</div>
                <div class="metric-note"><i class="bi bi-hdd-network"></i> monitor terminal fleet</div>
            </div>
        </div>
        <div class="card metric-card">
            <div class="card-body">
                <div class="metric-eyebrow">Employees</div>
                <div class="metric-value">Directory</div>
                <div class="metric-note"><i class="bi bi-people"></i> manage workforce records</div>
            </div>
        </div>
        <div class="card metric-card">
            <div class="card-body">
                <div class="metric-eyebrow">Attendance</div>
                <div class="metric-value">Reports</div>
                <div class="metric-note"><i class="bi bi-calendar2-check"></i> review daily activity</div>
            </div>
        </div>
        <div class="card metric-card">
            <div class="card-body">
                <div class="metric-eyebrow">Security</div>
                <div class="metric-value">Protected</div>
                <div class="metric-note"><i class="bi bi-shield-lock"></i> operator session active</div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card shell-card">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">Quick Access</h5>
                        <div class="shell-subtitle">Jump straight into the areas used most often during daily operations.</div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="toolbar-cluster">
                        <a href="{{ route('admin.dashboard') }}" class="btn btn-primary">
                            <i class="bi bi-grid-1x2 me-1"></i>
                            Open Dashboard
                        </a>
                        <a href="{{ route('admin.devices.index') }}" class="btn btn-outline-primary">
                            <i class="bi bi-hdd-network me-1"></i>
                            Devices
                        </a>
                        <a href="{{ route('admin.employees.index') }}" class="btn btn-outline-primary">
                            <i class="bi bi-people me-1"></i>
                            Employees
                        </a>
                        <a href="{{ route('admin.attendance.index') }}" class="btn btn-outline-primary">
                            <i class="bi bi-calendar2-week me-1"></i>
                            Attendance
                        </a>
                        <a href="{{ route('admin.logs.index') }}" class="btn btn-outline-primary">
                            <i class="bi bi-journal-code me-1"></i>
                            Logs
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shell-card h-100">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">Session Context</h5>
                        <div class="shell-subtitle">Current operator details for this authenticated session.</div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="stat-stack">
                        <div class="stat-pill">
                            <strong>{{ auth()->user()->name ?? 'Operator' }}</strong>
                            <span>Signed-in account name</span>
                        </div>
                        <div class="stat-pill">
                            <strong>{{ auth()->user()->email ?? 'No email set' }}</strong>
                            <span>Primary recovery and notification address</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
