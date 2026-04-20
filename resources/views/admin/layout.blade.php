<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'ADMS') - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body {
            display: flex;
            min-height: 100vh;
            background-color: #f8f9fa;
        }
        
        .sidebar {
            width: 250px;
            background-color: #2c3e50;
            color: white;
            padding-top: 20px;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }
        
        .sidebar a {
            color: white;
            text-decoration: none;
            padding: 12px 20px;
            display: block;
            transition: background-color 0.3s;
        }
        
        .sidebar a:hover,
        .sidebar a.active {
            background-color: #34495e;
        }
        
        .main-content {
            margin-left: 250px;
            flex: 1;
            padding: 20px;
        }
        
        .navbar-admin {
            background-color: white;
            border-bottom: 1px solid #e9ecef;
            padding: 15px 20px;
            margin-bottom: 20px;
        }
        
        .card {
            border: none;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        
        .badge-online {
            background-color: #27ae60;
        }
        
        .badge-offline {
            background-color: #e74c3c;
        }
        
        .badge-inactive {
            background-color: #95a5a6;
        }
    </style>
    @yield('extra_css')
    @stack('extra_css')
</head>
<body>
    <div class="sidebar">
        <div class="px-3 mb-4">
            <h5 class="mb-0"><i class="bi bi-speedometer2"></i> ADMS</h5>
            <small>Admin Panel</small>
        </div>

        <nav class="nav flex-column">
            <a href="{{ route('admin.dashboard') }}" class="nav-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
                <i class="bi bi-house-fill"></i> Dashboard
            </a>
            <a href="{{ route('admin.devices.index') }}" class="nav-link {{ request()->routeIs('admin.devices.*') ? 'active' : '' }}">
                <i class="bi bi-tv"></i> Devices
            </a>
            <a href="{{ route('admin.employees.index') }}" class="nav-link {{ request()->routeIs('admin.employees.*') ? 'active' : '' }}">
                <i class="bi bi-people"></i> Employees
            </a>
            <a href="{{ route('admin.credentials.index') }}" class="nav-link {{ request()->routeIs('admin.credentials.*') ? 'active' : '' }}">
                <i class="bi bi-credit-card-2-front"></i> Credentials
            </a>
            <a href="{{ route('admin.attendance.index') }}" class="nav-link {{ request()->routeIs('admin.attendance.*') ? 'active' : '' }}">
                <i class="bi bi-calendar-check"></i> Attendance
            </a>
            <a href="{{ route('admin.logs.index') }}" class="nav-link {{ request()->routeIs('admin.logs.*') ? 'active' : '' }}">
                <i class="bi bi-file-text"></i> Logs
            </a>
        </nav>
    </div>

    <div class="main-content">
        <div class="navbar-admin">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0">@yield('page_title', 'Dashboard')</h5>
                </div>
                <div>
                    <span class="me-3">Open Access</span>
                    <span class="badge bg-secondary">Public</span>
                </div>
            </div>
        </div>

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
            <h5>{{ __('validation.failed') }}</h5>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
                @endforeach
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        @endif

        @yield('content')
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    @yield('extra_js')
    @stack('extra_js')
</body>
</html>
