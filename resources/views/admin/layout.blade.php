<!DOCTYPE html>
<html lang="en">
@php
    $pageTitle = trim($__env->yieldContent('page_title', 'Dashboard'));
    $pageSubtitle = trim($__env->yieldContent('page_subtitle', 'Operational visibility for devices, biometric enrollment, and attendance workflows.'));
    $navItems = [
        [
            'route' => route('admin.dashboard'),
            'match' => 'admin.dashboard',
            'icon' => 'bi-grid-1x2-fill',
            'label' => 'Dashboard',
        ],
        [
            'route' => route('admin.devices.index'),
            'match' => 'admin.devices.*',
            'icon' => 'bi-hdd-network-fill',
            'label' => 'Devices',
        ],
        [
            'route' => route('admin.employees.index'),
            'match' => 'admin.employees.*',
            'icon' => 'bi-people-fill',
            'label' => 'Employees',
        ],
        [
            'route' => route('admin.credentials.index'),
            'match' => 'admin.credentials.*',
            'icon' => 'bi-person-vcard-fill',
            'label' => 'Credentials',
        ],
        [
            'route' => route('admin.attendance.index'),
            'match' => 'admin.attendance.*',
            'icon' => 'bi-calendar2-check-fill',
            'label' => 'Attendance',
        ],
        [
            'route' => route('admin.commands.index'),
            'match' => 'admin.commands.*',
            'icon' => 'bi-terminal-fill',
            'label' => 'Commands',
        ],
        [
            'route' => route('admin.logs.index'),
            'match' => 'admin.logs.*',
            'icon' => 'bi-journal-code',
            'label' => 'Logs',
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

                <div class="sidebar-section-label">Operations</div>
                <nav class="sidebar-nav">
                    @foreach ($navItems as $item)
                        <a href="{{ $item['route'] }}" class="nav-link {{ request()->routeIs($item['match']) ? 'active' : '' }}">
                            <i class="bi {{ $item['icon'] }}"></i>
                            <span>{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </nav>

                <div class="sidebar-note">
                    <strong>Workspace Status</strong>
                    <div class="small">Remote device management, biometric replay, and attendance processing are available from this console.</div>
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
                            <i class="bi bi-activity"></i>
                            Live Operations
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
