<!DOCTYPE html>
<html lang="en">
@php
    $pageTitle = trim($__env->yieldContent('page_title', 'Dashboard'));
    $pageSubtitle = trim($__env->yieldContent('page_subtitle', 'Device connectivity and attendance management.'));
    $originalMode = config('legacy_attendance.browser_enabled', false);

    // The existing ADMS Enterprise design is canonical. Every route appears
    // in the left navigation, grouped by actual function. Reference screenshots
    // from older BioCloud provide operational requirements, NOT visual branding.
    $navGroups = [
        'Overview' => [
            ['route' => route('admin.dashboard'), 'match' => 'admin.dashboard', 'icon' => 'bi-grid-1x2-fill', 'label' => 'Dashboard'],
            ['route' => route('admin.biocloud.system'), 'match' => 'admin.biocloud.system', 'icon' => 'bi-sliders', 'label' => 'System Status'],
        ],
        'Employees & Organization' => [
            ['route' => route('admin.directory.index', ['section' => 'employee']), 'match' => 'admin.directory.*', 'section' => 'employee', 'icon' => 'bi-people', 'label' => 'Employee Directory'],
            ['route' => route('admin.directory.index', ['section' => 'department']), 'match' => 'admin.directory.*', 'section' => 'department', 'icon' => 'bi-diagram-3', 'label' => 'Departments'],
            ['route' => route('admin.directory.index', ['section' => 'area']), 'match' => 'admin.directory.*', 'section' => 'area', 'icon' => 'bi-geo-alt', 'label' => 'Areas'],
            ['route' => route('admin.directory.index', ['section' => 'position']), 'match' => 'admin.directory.*', 'section' => 'position', 'icon' => 'bi-briefcase', 'label' => 'Positions'],
            ['route' => route('admin.directory.index', ['section' => 'holiday']), 'match' => 'admin.directory.*', 'section' => 'holiday', 'icon' => 'bi-calendar-event', 'label' => 'Holidays'],
            ['route' => route('admin.directory.index', ['section' => 'timetable']), 'match' => 'admin.directory.*', 'section' => 'timetable', 'icon' => 'bi-clock-history', 'label' => 'Timetables'],
            ['route' => route('admin.directory.index', ['section' => 'shift']), 'match' => 'admin.directory.*', 'section' => 'shift', 'icon' => 'bi-calendar-week', 'label' => 'Shifts'],
            ['route' => route('admin.directory.index', ['section' => 'department-schedule']), 'match' => 'admin.directory.*', 'section' => 'department-schedule', 'icon' => 'bi-calendar-range', 'label' => 'Department Schedules'],
            ['route' => route('admin.directory.index', ['section' => 'employee-schedule']), 'match' => 'admin.directory.*', 'section' => 'employee-schedule', 'icon' => 'bi-calendar2-week', 'label' => 'Employee Schedules'],
            ['route' => route('admin.directory.index', ['section' => 'temporary-schedule']), 'match' => 'admin.directory.*', 'section' => 'temporary-schedule', 'icon' => 'bi-calendar-plus', 'label' => 'Temporary Schedules'],
            ['route' => route('admin.directory.index', ['section' => 'employee-leave']), 'match' => 'admin.directory.*', 'section' => 'employee-leave', 'icon' => 'bi-person-dash', 'label' => 'Employee Leave'],
            ['route' => route('admin.employees.index'), 'match' => 'admin.employees.*', 'icon' => 'bi-person-gear', 'label' => 'ADMS Employees'],
            ['route' => route('admin.credentials.index'), 'match' => 'admin.credentials.*', 'icon' => 'bi-person-vcard', 'label' => 'Credentials & Enrolment'],
            ['route' => route('admin.credentials.no-credential'), 'match' => 'admin.credentials.no-credential', 'icon' => 'bi-person-exclamation', 'label' => 'Missing Credentials'],
        ],
        'Devices & Commands' => [
            ['route' => route('admin.devices.index'), 'match' => 'admin.devices.*', 'icon' => 'bi-hdd-network', 'label' => 'Device Fleet & Licenses'],
            ['route' => route('admin.device-connectivity.index'), 'match' => 'admin.device-connectivity.*', 'icon' => 'bi-broadcast-pin', 'label' => 'Device Connectivity'],
            ['route' => route('admin.legacy.index', ['tab' => 'devices']), 'match' => 'admin.legacy.index', 'tab' => 'devices', 'icon' => 'bi-database', 'label' => 'Original Device Registry'],
            ['route' => route('admin.devices.create'), 'match' => 'admin.devices.create', 'icon' => 'bi-plus-square', 'label' => 'Register Device'],
            ['route' => route('admin.commands.index'), 'match' => 'admin.commands.*', 'icon' => 'bi-terminal', 'label' => 'Device Commands'],
            ['route' => route('admin.commands.create'), 'match' => 'admin.commands.create', 'icon' => 'bi-send', 'label' => 'New Device Command'],
        ],
        'Attendance & Reports' => [
            ['route' => route('admin.legacy.index', ['tab' => 'attendance']), 'match' => 'admin.legacy.index', 'tab' => 'attendance', 'icon' => 'bi-list-check', 'label' => 'Attendance Transactions'],
            ['route' => route('admin.attendance.index'), 'match' => 'admin.attendance.*', 'icon' => 'bi-activity', 'label' => 'Live Attendance'],
            ['route' => route('admin.attendance-reports.show', ['report' => 'first-last']), 'match' => 'admin.attendance-reports.*', 'report' => 'first-last', 'icon' => 'bi-stopwatch', 'label' => 'First & Last'],
            ['route' => route('admin.attendance-reports.show', ['report' => 'first-in-last-out']), 'match' => 'admin.attendance-reports.*', 'report' => 'first-in-last-out', 'icon' => 'bi-box-arrow-in-right', 'label' => 'First In / Last Out'],
            ['route' => route('admin.attendance-reports.show', ['report' => 'multi-in-out']), 'match' => 'admin.attendance-reports.*', 'report' => 'multi-in-out', 'icon' => 'bi-arrow-left-right', 'label' => 'Multi In / Out'],
            ['route' => route('admin.attendance-reports.show', ['report' => 'daily']), 'match' => 'admin.attendance-reports.*', 'report' => 'daily', 'icon' => 'bi-calendar-day', 'label' => 'Daily Attendance'],
            ['route' => route('admin.attendance-reports.show', ['report' => 'monthly']), 'match' => 'admin.attendance-reports.*', 'report' => 'monthly', 'icon' => 'bi-calendar-month', 'label' => 'Monthly Attendance'],
            ['route' => route('admin.attendance-reports.show', ['report' => 'summary']), 'match' => 'admin.attendance-reports.*', 'report' => 'summary', 'icon' => 'bi-clipboard-data', 'label' => 'Attendance Summary'],
        ],
        'System & Audit' => [
            ['route' => route('admin.logs.index'), 'match' => 'admin.logs.*', 'icon' => 'bi-journal-code', 'label' => 'ADMS Request Logs'],
            ['route' => route('admin.legacy.index', ['tab' => 'templates']), 'match' => 'admin.legacy.index', 'tab' => 'templates', 'icon' => 'bi-fingerprint', 'label' => 'Template Metadata'],
            ['route' => route('admin.legacy.index', ['tab' => 'employees']), 'match' => 'admin.legacy.index', 'tab' => 'employees', 'icon' => 'bi-table', 'label' => 'Original Employee Source'],
        ],
    ];
@endphp
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'ADMS') - Admin Panel</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800|instrument-sans:400,500,600,700" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="{{ asset('enterprise-ui.css') }}">
    @yield('extra_css')
    @stack('extra_css')
</head>
<body>
    <div class="admin-overlay" data-sidebar-close></div>

    <div class="admin-shell">
        <aside class="admin-sidebar" id="adminSidebar">
            <div class="sidebar-inner">
                <div class="sidebar-brand">
                    <div class="sidebar-brand-mark">
                        <i class="bi bi-fingerprint"></i>
                    </div>
                    <div class="sidebar-brand-text">
                        <strong>ADMS Enterprise</strong>
                        <span>Biometric control workspace</span>
                    </div>
                </div>

                <nav class="sidebar-nav" aria-label="All ADMS Enterprise pages">
                    @foreach ($navGroups as $group => $items)
                        <div class="sidebar-section-label">{{ $group }}</div>
                        @foreach ($items as $item)
                            @php
                                $active = request()->routeIs($item['match'])
                                    && (!isset($item['tab']) || request('tab', 'employees') === $item['tab'])
                                    && (!isset($item['section']) || request()->route('section') === $item['section'])
                                    && (!isset($item['report']) || request()->route('report') === $item['report']);
                            @endphp
                            <a href="{{ $item['route'] }}" class="nav-link {{ $active ? 'active' : '' }}">
                                <i class="bi {{ $item['icon'] }}"></i>
                                <span>{{ $item['label'] }}</span>
                            </a>
                        @endforeach
                    @endforeach
                </nav>

                <div class="sidebar-note">
                    <strong>Workspace Status</strong>
                    <div class="small">
                        @if ($originalMode)
                            Source: Att2000_V4.dbo · operations: Att2000_V4.adms. Device commands require authenticated dispatch; attendance reception continues independently.
                        @else
                            Remote device management and attendance workflows are available from this console.
                        @endif
                    </div>
                </div>
            </div>
        </aside>

        <main class="admin-workspace">
            <div class="workspace-topbar">
                <button type="button" class="sidebar-toggle" id="sidebarToggle" aria-label="Open navigation">
                    <i class="bi bi-list"></i>
                </button>

                <div class="workspace-context">
                    <span class="workspace-context-pill">
                        <i class="bi bi-shield-check"></i>
                        Enterprise Access
                    </span>
                    <span class="workspace-context-pill">
                        <i class="bi bi-calendar3"></i>
                        {{ now()->format('d M Y') }}
                    </span>
                </div>

                <div class="workspace-context">
                    @auth
                        <span class="workspace-context-pill">
                            <i class="bi bi-person-circle"></i>
                            {{ auth()->user()->name }}
                        </span>
                    @endauth
                    <span class="workspace-context-pill">
                        <i class="bi bi-globe2"></i>
                        Open Access
                    </span>
                </div>
            </div>

            <header class="workspace-header">
                <div class="workspace-header-copy">
                    <span class="eyebrow">Admin Workspace</span>
                    <h1>{{ $pageTitle }}</h1>
                    <p>{{ $pageSubtitle }}</p>
                </div>

                <div class="workspace-header-meta">
                    @hasSection('page_actions')
                        <div class="toolbar-actions">
                            @yield('page_actions')
                        </div>
                    @endif

                    <div class="meta-stack">
                        <span class="meta-chip">
                            <i class="bi bi-broadcast-pin"></i>
                            ADMS Push
                        </span>
                        <span class="meta-chip">
                            <i class="bi {{ $originalMode ? 'bi-database' : 'bi-activity' }}"></i>
                            {{ $originalMode ? 'SQL Server Source' : 'Live Operations' }}
                        </span>
                    </div>
                </div>
            </header>

            @if (session('success'))
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    {{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            @if (session('error'))
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    {{ session('error') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            @if ($errors->any())
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <strong class="d-block mb-1">Please review the highlighted issues.</strong>
                    <ul class="mb-0 ps-3">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            <section class="content-stage">
                @yield('content')
            </section>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (() => {
            const body = document.body;
            const toggle = document.getElementById('sidebarToggle');
            const closeTargets = document.querySelectorAll('[data-sidebar-close]');

            if (!toggle) {
                return;
            }

            toggle.addEventListener('click', () => {
                body.classList.toggle('sidebar-open');
            });

            closeTargets.forEach((target) => {
                target.addEventListener('click', () => {
                    body.classList.remove('sidebar-open');
                });
            });

            window.addEventListener('resize', () => {
                if (window.innerWidth > 991) {
                    body.classList.remove('sidebar-open');
                }
            });
        })();
    </script>
    @yield('extra_js')
    @stack('extra_js')
</body>
</html>
