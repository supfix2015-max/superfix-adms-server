<!DOCTYPE html>
<html lang="en">
@php
    $pageTitle = trim($__env->yieldContent('page_title', 'Dashboard'));
    $pageSubtitle = trim($__env->yieldContent('page_subtitle', 'Device connectivity and attendance management.'));
    $originalMode = config('legacy_attendance.browser_enabled', false);

    // Operational capabilities from the former BioCloud are grouped
    // inside the original ADMS Enterprise UI, never visually copied.
    // All links correspond to actual authenticated routes. Specific actions
    // require a selected device/employee on the destination page.
    $navGroups = [
        [
            'id' => 'overview', 'label' => 'Overview', 'icon' => 'bi-grid-1x2',
            'children' => [
                ['label' => 'Dashboard', 'icon' => 'bi-speedometer2', 'route' => route('admin.dashboard'), 'matches' => ['admin.dashboard']],
                ['label' => 'System Status', 'icon' => 'bi-sliders', 'route' => route('admin.biocloud.system'), 'matches' => ['admin.biocloud.system']],
            ],
        ],
        [
            'id' => 'employees', 'label' => 'Employees & Organization', 'icon' => 'bi-people',
            'children' => [
                ['label' => 'Employee Management', 'icon' => 'bi-person-lines-fill', 'children' => [
                    ['label' => 'Original Employee Directory', 'icon' => 'bi-person-vcard', 'route' => route('admin.directory.index', ['section' => 'employee']), 'matches' => ['admin.directory.index'], 'section' => 'employee'],
                    ['label' => 'ADMS Employees', 'icon' => 'bi-person-gear', 'route' => route('admin.employees.index'), 'matches' => ['admin.employees.*']],
                    ['label' => 'Original Employee Source', 'icon' => 'bi-database', 'route' => route('admin.legacy.index', ['tab' => 'employees']), 'matches' => ['admin.legacy.index'], 'tab' => 'employees'],
                ]],
                ['label' => 'Organizational Structure', 'icon' => 'bi-diagram-3', 'children' => [
                    ['label' => 'Departments', 'icon' => 'bi-building', 'route' => route('admin.directory.index', ['section' => 'department']), 'matches' => ['admin.directory.index'], 'section' => 'department'],
                    ['label' => 'Areas', 'icon' => 'bi-geo-alt', 'route' => route('admin.directory.index', ['section' => 'area']), 'matches' => ['admin.directory.index'], 'section' => 'area'],
                    ['label' => 'Positions', 'icon' => 'bi-briefcase', 'route' => route('admin.directory.index', ['section' => 'position']), 'matches' => ['admin.directory.index'], 'section' => 'position'],
                ]],
                ['label' => 'Schedules & Leave', 'icon' => 'bi-calendar-week', 'children' => [
                    ['label' => 'Holidays', 'icon' => 'bi-calendar-event', 'route' => route('admin.directory.index', ['section' => 'holiday']), 'matches' => ['admin.directory.index'], 'section' => 'holiday'],
                    ['label' => 'Timetables', 'icon' => 'bi-clock-history', 'route' => route('admin.directory.index', ['section' => 'timetable']), 'matches' => ['admin.directory.index'], 'section' => 'timetable'],
                    ['label' => 'Shifts', 'icon' => 'bi-clock', 'route' => route('admin.directory.index', ['section' => 'shift']), 'matches' => ['admin.directory.index'], 'section' => 'shift'],
                    ['label' => 'Department Schedules', 'icon' => 'bi-calendar-range', 'route' => route('admin.directory.index', ['section' => 'department-schedule']), 'matches' => ['admin.directory.index'], 'section' => 'department-schedule'],
                    ['label' => 'Employee Schedules', 'icon' => 'bi-calendar2-week', 'route' => route('admin.directory.index', ['section' => 'employee-schedule']), 'matches' => ['admin.directory.index'], 'section' => 'employee-schedule'],
                    ['label' => 'Temporary Schedules', 'icon' => 'bi-calendar-plus', 'route' => route('admin.directory.index', ['section' => 'temporary-schedule']), 'matches' => ['admin.directory.index'], 'section' => 'temporary-schedule'],
                    ['label' => 'Employee Leave', 'icon' => 'bi-person-dash', 'route' => route('admin.directory.index', ['section' => 'employee-leave']), 'matches' => ['admin.directory.index'], 'section' => 'employee-leave'],
                ]],
                ['label' => 'Credentials & Enrollment', 'icon' => 'bi-fingerprint', 'children' => [
                    ['label' => 'Credentials', 'icon' => 'bi-person-vcard', 'route' => route('admin.credentials.index'), 'matches' => ['admin.credentials.index']],
                    ['label' => 'Missing Credentials', 'icon' => 'bi-person-exclamation', 'route' => route('admin.credentials.no-credential'), 'matches' => ['admin.credentials.no-credential']],
                ]],
            ],
        ],
        [
            'id' => 'devices', 'label' => 'Devices & Commands', 'icon' => 'bi-hdd-network',
            'children' => [
                ['label' => 'Device Fleet', 'icon' => 'bi-hdd-stack', 'children' => [
                    ['label' => 'Devices & License Flags', 'icon' => 'bi-hdd-network', 'route' => route('admin.devices.index'), 'matches' => ['admin.devices.index', 'admin.devices.show', 'admin.devices.edit']],
                    ['label' => 'Device Connectivity', 'icon' => 'bi-broadcast', 'route' => route('admin.device-connectivity.index'), 'matches' => ['admin.device-connectivity.index']],
                    ['label' => 'Original Device Registry', 'icon' => 'bi-database', 'route' => route('admin.legacy.index', ['tab' => 'devices']), 'matches' => ['admin.legacy.index'], 'tab' => 'devices'],
                    ['label' => 'Register Device', 'icon' => 'bi-plus-square', 'route' => route('admin.devices.create'), 'matches' => ['admin.devices.create']],
                ]],
                ['label' => 'Device Operations', 'icon' => 'bi-terminal', 'children' => [
                    ['label' => 'Command Center', 'icon' => 'bi-list-task', 'route' => route('admin.commands.index'), 'matches' => ['admin.commands.index', 'admin.commands.show']],
                    ['label' => 'New Device Command', 'icon' => 'bi-send', 'route' => route('admin.commands.create'), 'matches' => ['admin.commands.create']],
                    ['label' => 'Download / Sync Data', 'icon' => 'bi-arrow-down-up', 'route' => route('admin.devices.index'), 'matches' => [], 'hint' => 'Select device then Data Transfer'],
                    ['label' => 'Clear Pending Commands', 'icon' => 'bi-x-octagon', 'route' => route('admin.devices.index'), 'matches' => [], 'hint' => 'Select device then Device Menu'],
                ]],
            ],
        ],
        [
            'id' => 'attendance', 'label' => 'Attendance & Reports', 'icon' => 'bi-calendar2-check',
            'children' => [
                ['label' => 'Attendance', 'icon' => 'bi-clipboard-check', 'children' => [
                    ['label' => 'Transactions', 'icon' => 'bi-list-check', 'route' => route('admin.legacy.index', ['tab' => 'attendance']), 'matches' => ['admin.legacy.index'], 'tab' => 'attendance'],
                    ['label' => 'Live Attendance', 'icon' => 'bi-activity', 'route' => route('admin.attendance.index'), 'matches' => ['admin.attendance.index']],
                ]],
                ['label' => 'Attendance Reports', 'icon' => 'bi-bar-chart', 'children' => [
                    ['label' => 'First & Last', 'icon' => 'bi-stopwatch', 'route' => route('admin.attendance-reports.show', ['report' => 'first-last']), 'matches' => ['admin.attendance-reports.show'], 'report' => 'first-last'],
                    ['label' => 'First In / Last Out', 'icon' => 'bi-box-arrow-in-right', 'route' => route('admin.attendance-reports.show', ['report' => 'first-in-last-out']), 'matches' => ['admin.attendance-reports.show'], 'report' => 'first-in-last-out'],
                    ['label' => 'Multi In / Out', 'icon' => 'bi-arrow-left-right', 'route' => route('admin.attendance-reports.show', ['report' => 'multi-in-out']), 'matches' => ['admin.attendance-reports.show'], 'report' => 'multi-in-out'],
                    ['label' => 'Daily Attendance', 'icon' => 'bi-calendar-day', 'route' => route('admin.attendance-reports.show', ['report' => 'daily']), 'matches' => ['admin.attendance-reports.show'], 'report' => 'daily'],
                    ['label' => 'Monthly Attendance', 'icon' => 'bi-calendar-month', 'route' => route('admin.attendance-reports.show', ['report' => 'monthly']), 'matches' => ['admin.attendance-reports.show'], 'report' => 'monthly'],
                    ['label' => 'Attendance Summary', 'icon' => 'bi-clipboard-data', 'route' => route('admin.attendance-reports.show', ['report' => 'summary']), 'matches' => ['admin.attendance-reports.show'], 'report' => 'summary'],
                ]],
            ],
        ],
        [
            'id' => 'system', 'label' => 'System & Audit', 'icon' => 'bi-shield-check',
            'children' => [
                ['label' => 'ADMS Request Logs', 'icon' => 'bi-journal-code', 'route' => route('admin.logs.index'), 'matches' => ['admin.logs.index']],
                ['label' => 'Template Metadata', 'icon' => 'bi-fingerprint', 'route' => route('admin.legacy.index', ['tab' => 'templates']), 'matches' => ['admin.legacy.index'], 'tab' => 'templates'],
            ],
        ],
    ];

    // The ZenHR connector belongs to AT2 only; never show it in AT1.
    if (config('zenhr.enabled', false) && request()->getHost() === 'at2.superfix.ae') {
        $navGroups[] = [
            'id' => 'integrations',
            'label' => 'Integrations',
            'icon' => 'bi-plug',
            'children' => [
                [
                    'label' => 'ZenHR',
                    'icon' => 'bi-cloud-check',
                    'route' => route('admin.zenhr.index'),
                    'matches' => ['admin.zenhr.*'],
                ],
            ],
        ];
    }

    $isActiveNavLeaf = static function (array $item): bool {
        if (empty($item['matches']) || ! request()->routeIs(...$item['matches'])) {
            return false;
        }

        foreach (['tab', 'section', 'report'] as $context) {
            if (! array_key_exists($context, $item)) {
                continue;
            }
            $actual = $context === 'tab' ? request()->query('tab', 'employees') : request()->route($context);
            if ((string) $actual !== (string) $item[$context]) {
                return false;
            }
        }

        return true;
    };

    $hasActiveNav = static function (array $node) use (&$hasActiveNav, $isActiveNavLeaf): bool {
        if (! empty($node['children'])) {
            foreach ($node['children'] as $child) {
                if ($hasActiveNav($child)) {
                    return true;
                }
            }

            return false;
        }

        return $isActiveNavLeaf($node);
    };
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
    <link rel="stylesheet" href="/enterprise-ui.css?v={{ filemtime(public_path('enterprise-ui.css')) }}">
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

                <nav class="sidebar-nav sidebar-accordion" aria-label="ADMS Enterprise navigation">
                    @foreach ($navGroups as $group)
                        @php($groupActive = $hasActiveNav($group))
                        <details class="sidebar-group" data-nav-group="{{ $group['id'] }}" @if($groupActive) open @endif>
                            <summary class="sidebar-group-toggle {{ $groupActive ? 'is-current' : '' }}">
                                <i class="bi {{ $group['icon'] }}" aria-hidden="true"></i>
                                <span>{{ $group['label'] }}</span>
                                <i class="bi bi-chevron-down sidebar-chevron" aria-hidden="true"></i>
                            </summary>
                            <div class="sidebar-group-contents">
                                @foreach ($group['children'] as $item)
                                    @if (! empty($item['children']))
                                        @php($subActive = $hasActiveNav($item))
                                        <details class="sidebar-subgroup" data-nav-subgroup="{{ $group['id'] }}-{{ $loop->index }}" @if($subActive) open @endif>
                                            <summary class="sidebar-subgroup-toggle {{ $subActive ? 'is-current' : '' }}">
                                                <i class="bi {{ $item['icon'] }}" aria-hidden="true"></i>
                                                <span>{{ $item['label'] }}</span>
                                                <i class="bi bi-chevron-down sidebar-chevron" aria-hidden="true"></i>
                                            </summary>
                                            <div class="sidebar-subgroup-contents">
                                                @foreach ($item['children'] as $action)
                                                    @php($active = $isActiveNavLeaf($action))
                                                    <a href="{{ $action['route'] }}"
                                                       class="nav-link sidebar-subitem {{ $active ? 'active' : '' }}"
                                                       @if(isset($action['hint'])) title="{{ $action['hint'] }}" @endif
                                                       @if($active) aria-current="page" @endif>
                                                        <i class="bi {{ $action['icon'] }}" aria-hidden="true"></i>
                                                        <span>{{ $action['label'] }}</span>
                                                    </a>
                                                @endforeach
                                            </div>
                                        </details>
                                    @else
                                        @php($active = $isActiveNavLeaf($item))
                                        <a href="{{ $item['route'] }}" class="nav-link sidebar-subitem {{ $active ? 'active' : '' }}"
                                           @if($active) aria-current="page" @endif>
                                            <i class="bi {{ $item['icon'] }}" aria-hidden="true"></i>
                                            <span>{{ $item['label'] }}</span>
                                        </a>
                                    @endif
                                @endforeach
                            </div>
                        </details>
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

            // Nested native details provide keyboard + screenreader behavior.
            // Keep only ONE top-level section open; opening a nested section
            // closes its siblings. Active-route sections are open on page load.
            const sidebar = document.getElementById('adminSidebar');
            const rootGroups = [...sidebar.querySelectorAll('.sidebar-group')];
            rootGroups.forEach(group => group.addEventListener('toggle', () => {
                if (group.open) {
                    rootGroups.filter(other => other !== group).forEach(other => { other.open = false; });
                }
            }));
            sidebar.querySelectorAll('.sidebar-subgroup').forEach(section => {
                section.addEventListener('toggle', () => {
                    if (!section.open) return;
                    const others = section.parentElement.querySelectorAll('.sidebar-subgroup');
                    others.forEach(other => { if (other !== section) other.open = false; });
                });
            });
        })();
    </script>
    @yield('extra_js')
    @stack('extra_js')
</body>
</html>
