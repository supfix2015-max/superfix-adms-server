<!DOCTYPE html>
<html lang="en">
@php
    $employeeSections = [
        'employee' => ['Employee', 'bi-person-vcard'],
        'department' => ['Department', 'bi-files'],
        'area' => ['Area', 'bi-globe'],
        'position' => ['Position', 'bi-briefcase'],
        'holiday' => ['Holiday', 'bi-calendar-event'],
        'timetable' => ['Timetable', 'bi-clock'],
        'shift' => ['Shift', 'bi-calendar-week'],
        'department-schedule' => ['Department Schedule', 'bi-rulers'],
        'employee-schedule' => ['Employee Schedule', 'bi-calendar2-range'],
        'temporary-schedule' => ['Temporary Schedule', 'bi-calendar2-plus'],
        'employee-leave' => ['Employee Leave', 'bi-house'],
    ];
    $attendanceSections = [
        'transaction' => ['Transaction', 'bi-receipt'],
        'first-last' => ['First & Last', 'bi-wallet2'],
        'first-in-last-out' => ['First in last out', 'bi-calendar3'],
        'multi-in-out' => ['Multi In Out', 'bi-list-ol'],
        'daily' => ['Daily Attendance', 'bi-clock-history'],
        'monthly' => ['Monthly Attendance', 'bi-bar-chart'],
        'summary' => ['Attendance Summary', 'bi-card-checklist'],
    ];
    $section = request()->route('section');
    $activeArea = request()->routeIs('admin.directory.*') ? 'employee'
        : (request()->routeIs('admin.fleet.*') ? 'devices'
            : (request()->routeIs('admin.attendance-reports.*')
                || (request()->routeIs('admin.legacy.index') && request('tab') === 'attendance') ? 'attendance'
                    : (request()->routeIs('admin.biocloud.system') ? 'system' : 'home')));
    $activeAttendance = request()->routeIs('admin.attendance-reports.*')
        ? request()->route('report') : 'transaction';
@endphp
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'BioCloud') — Superfix ADMS</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="{{ asset('biocloud-ui.css') }}">
    @yield('extra_css')
    @stack('extra_css')
</head>
<body class="bc-body">
    <div class="bc-app">
        <header class="bc-topbar">
            <a class="bc-brand" href="{{ route('admin.biocloud.home') }}">
                <i class="bi bi-clock-history" aria-hidden="true"></i>
                <span>Bio<strong>Cloud</strong><small> · Superfix</small></span>
            </a>
            <button type="button" class="bc-mobile-menu" data-bc-sidebar-toggle aria-label="Toggle sidebar">
                <i class="bi bi-list"></i>
            </button>
            <nav class="bc-topnav" aria-label="Main sections">
                <a href="{{ route('admin.biocloud.home') }}" class="{{ $activeArea === 'home' ? 'active' : '' }}">Home</a>
                <a href="{{ route('admin.directory.index', ['section' => 'employee']) }}" class="{{ $activeArea === 'employee' ? 'active' : '' }}">Employee</a>
                <a href="{{ route('admin.fleet.index') }}" class="{{ $activeArea === 'devices' ? 'active' : '' }}">Devices</a>
                <a href="{{ route('admin.legacy.index', ['tab' => 'attendance']) }}" class="{{ $activeArea === 'attendance' ? 'active' : '' }}">Attendance</a>
                <a href="{{ route('admin.biocloud.system') }}" class="{{ $activeArea === 'system' ? 'active' : '' }}">System</a>
            </nav>
            <div class="bc-account"><i class="bi bi-person-circle"></i> {{ auth()->user()?->name ?? 'Admin' }}</div>
        </header>
        <div class="bc-screen">
            <aside class="bc-sidebar" data-bc-sidebar>
                @if ($activeArea === 'employee')
                    @foreach ($employeeSections as $slug => $config)
                        <a href="{{ route('admin.directory.index', ['section' => $slug]) }}"
                            class="bc-sidebar-link {{ $section === $slug ? 'active' : '' }}">
                            <i class="bi {{ $config[1] }}"></i> <span>{{ $config[0] }}</span>
                        </a>
                    @endforeach
                @elseif ($activeArea === 'devices')
                    <a class="bc-sidebar-link active" href="{{ route('admin.fleet.index') }}">
                        <i class="bi bi-hdd-network"></i> Devices
                    </a>
                    <a class="bc-sidebar-link" href="{{ route('admin.device-connectivity.index') }}">
                        <i class="bi bi-broadcast"></i> Device Connectivity
                    </a>
                    <a class="bc-sidebar-link" href="{{ route('admin.commands.index') }}">
                        <i class="bi bi-terminal"></i> Device Commands
                    </a>
                    <a class="bc-sidebar-link" href="{{ route('admin.logs.index') }}">
                        <i class="bi bi-journal-text"></i> Device Logs
                    </a>
                @elseif ($activeArea === 'attendance')
                    @foreach ($attendanceSections as $slug => $config)
                        <a class="bc-sidebar-link {{ $activeAttendance === $slug ? 'active' : '' }}"
                           href="{{ $slug === 'transaction'
                            ? route('admin.legacy.index', ['tab' => 'attendance'])
                            : route('admin.attendance-reports.show', ['report' => $slug]) }}">
                            <i class="bi {{ $config[1] }}"></i> <span>{{ $config[0] }}</span>
                        </a>
                    @endforeach
                @elseif ($activeArea === 'system')
                    <a class="bc-sidebar-link active" href="{{ route('admin.biocloud.system') }}"><i class="bi bi-tools"></i> System</a>
                    <a class="bc-sidebar-link" href="{{ route('admin.commands.index') }}"><i class="bi bi-terminal"></i> Command Center</a>
                    <a class="bc-sidebar-link" href="{{ route('admin.logs.index') }}"><i class="bi bi-journal-code"></i> ADMS Logs</a>
                    <a class="bc-sidebar-link" href="{{ route('admin.legacy.index', ['tab' => 'templates']) }}"><i class="bi bi-fingerprint"></i> Template Metadata</a>
                @else
                    <a class="bc-sidebar-link active" href="{{ route('admin.biocloud.home') }}"><i class="bi bi-house"></i> Overview</a>
                    <a class="bc-sidebar-link" href="{{ route('admin.device-connectivity.index') }}"><i class="bi bi-broadcast"></i> Connectivity</a>
                @endif
            </aside>
            <main class="bc-main">
                <div class="bc-main-inner">
                    @if (session('success'))
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            {{ session('success') }} <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    @endif
                    @if (session('error'))
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            {{ session('error') }} <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    @endif
                    @if ($errors->any())
                        <div class="alert alert-danger" role="alert">
                            <strong>Review this request:</strong>
                            <ul class="mb-0">
                                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                            </ul>
                        </div>
                    @endif
                    @yield('content')
                </div>
                <footer class="bc-footer">
                    Att2000_V4 original data · ADMS operational schema isolated · UAE (UTC+04)
                </footer>
            </main>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    (() => {
        const toggle = document.querySelector('[data-bc-sidebar-toggle]');
        const sidebar = document.querySelector('[data-bc-sidebar]');
        toggle?.addEventListener('click', () => sidebar?.classList.toggle('open'));
    })();
    </script>
    @yield('extra_js')
    @stack('extra_js')
</body>
</html>
