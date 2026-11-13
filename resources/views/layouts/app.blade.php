<!DOCTYPE html>
<html lang="en">
@php
    $isAuthPage = request()->routeIs('login', 'register', 'password.*', 'verification.*', 'admin-password.*');
@endphp
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'ADMS')</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800|instrument-sans:400,500,600,700" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://cdn.datatables.net/1.11.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/buttons/2.2.2/css/buttons.bootstrap5.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/enterprise-ui.css?v={{ filemtime(public_path('enterprise-ui.css')) }}">
    @yield('extra_css')
    @stack('extra_css')
</head>
<body>
    @if (! $isAuthPage)
        <div class="legacy-navbar">
            <nav class="navbar navbar-expand-lg navbar-light">
                <div class="container-fluid px-0">
                    <a class="navbar-brand fw-bold" href="{{ route('admin.dashboard') }}">
                        <i class="bi bi-fingerprint me-2 text-primary"></i>
                        ADMS Enterprise
                    </a>

                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#appNavbar">
                        <span class="navbar-toggler-icon"></span>
                    </button>

                    <div class="collapse navbar-collapse" id="appNavbar">
                        <ul class="navbar-nav me-auto">
                            @auth
                                <li class="nav-item">
                                    <a class="nav-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}" href="{{ route('admin.dashboard') }}">Dashboard</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link {{ request()->routeIs('admin.attendance.*') ? 'active' : '' }}" href="{{ route('admin.attendance.index') }}">Attendance</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link {{ request()->routeIs('admin.devices.*') ? 'active' : '' }}" href="{{ route('admin.devices.index') }}">Devices</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link {{ request()->routeIs('admin.employees.*') ? 'active' : '' }}" href="{{ route('admin.employees.index') }}">Employees</a>
                                </li>
                                @if (Route::has('users.index'))
                                    <li class="nav-item">
                                        <a class="nav-link {{ request()->routeIs('users.*') ? 'active' : '' }}" href="{{ route('users.index') }}">Admins</a>
                                    </li>
                                @endif
                            @endauth
                        </ul>

                        <ul class="navbar-nav ms-auto">
                            @auth
                                <li class="nav-item dropdown">
                                    <a class="nav-link dropdown-toggle fw-semibold" href="#" role="button" data-bs-toggle="dropdown">
                                        <i class="bi bi-person-circle me-1"></i>
                                        {{ auth()->user()->name }}
                                    </a>
                                    <div class="dropdown-menu dropdown-menu-end border-0 shadow">
                                        <a class="dropdown-item" href="{{ route('admin.dashboard') }}">Open Console</a>
                                        <a class="dropdown-item" href="{{ route('logout') }}"
                                            onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
                                            Logout
                                        </a>
                                        <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
                                            @csrf
                                        </form>
                                    </div>
                                </li>
                            @else
                                <li class="nav-item">
                                    <a class="nav-link fw-semibold" href="{{ route('login') }}">Login</a>
                                </li>
                                @if (Route::has('register'))
                                    <li class="nav-item">
                                        <a class="nav-link fw-semibold" href="{{ route('register') }}">Register</a>
                                    </li>
                                @endif
                            @endauth
                        </ul>
                    </div>
                </div>
            </nav>
        </div>
    @endif

    <main class="{{ $isAuthPage ? 'auth-shell auth-stage' : 'app-frame' }}">
        @if (session('success'))
            <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if (session('failed'))
            <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                {{ session('failed') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if ($errors->any() && ! $isAuthPage)
            <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                <strong class="d-block mb-1">Please review the highlighted issues.</strong>
                <ul class="mb-0 ps-3">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @yield('content')
    </main>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-validate/1.19.0/jquery.validate.js"></script>
    <script src="https://cdn.datatables.net/1.11.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.2.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.2.2/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.2.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.2.2/js/buttons.print.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.2.2/js/buttons.colVis.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    @yield('scripts')
    @yield('extra_js')
    @stack('extra_js')
</body>
</html>
