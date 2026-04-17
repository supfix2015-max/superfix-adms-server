<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Adms Logs</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/5.0.1/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/buttons/2.2.2/css/buttons.bootstrap5.min.css" rel="stylesheet">

    <style>
        @media (max-width: 991.98px) {
            .navbar-collapse {
                position: fixed;
                top: 56px;
                left: -100%;
                padding-left: 15px;
                padding-right: 15px;
                padding-bottom: 15px;
                width: 75%;
                height: 100%;
                background-color: #f8f9fa;
                transition: all 0.3s ease-in-out;
                z-index: 1000;
            }

            .navbar-collapse.show {
                left: 0;
            }

            body.menu-open {
                overflow: hidden;
            }

            .navbar-toggler {
                z-index: 1001;
            }
        }

        ul.pagination {
            margin-bottom: 30px !important;
        }

        .body-container {
            flex: 1;
        }
    </style>
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-light bg-light">
        <div class="container align-items-stretch">
            <a class="navbar-brand" href="{{ route('admin.attendance.index') }}">
                <img src="/logo.svg" alt="">
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
                aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            @if (Auth::check())
                <div class="collapse navbar-collapse" id="navbarNav">
                    <ul class="navbar-nav">
                        <li class="nav-item">
                            <a class="nav-link" href="{{ route('admin.attendance.index') }}">Attendance</a>
                        </li>
                        <!-- if is_admin is true then show this link -->
                        @if (Auth::user()->isAdmin())
                            <li class="nav-item">
                                <a class="nav-link" href="{{ route('admin.devices.index') }}">Devices</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="{{ route('admin.employees.index') }}">Employees</a>
                            </li>
                        @endif                      
                        <li class="nav-item">
                            <a class="nav-link" href="{{ route('admin.dashboard') }}">Dashboard</a>
                        </li>
                    </ul>
                </div>
            @endif
            <span class="navbar-text d-none d-lg-block">
                <ul class="navbar-nav ms-auto">
                    @if (Auth::check())
                        <!-- Authentication Links -->
                        <li class="nav-item dropdown">
                            <a id="navbarDropdown" class="nav-link dropdown-toggle" href="#" role="button"
                                data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false" v-pre>
                                {{Auth::user()->name}}
                            </a>

                            <div class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdown">
                                <a class="dropdown-item" href="{{ route('logout') }}"
                                    onclick="event.preventDefault();
                                                                                                                                 document.getElementById('logout-form').submit();">
                                    {{ __('Logout') }}
                                </a>

                                <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
                                    @csrf
                                </form>
                            </div>
                        </li>
                    @endif

                </ul>
            </span>
        </div>
    </nav>

    <div class="container body-container mt-4">
        @yield('content')
        @yield('scripts')
    </div>

    <footer class="bg-body-tertiary text-center text-lg-start">
        <!-- <div class="text-center p-3" style="background-color: rgba(0, 0, 0, 0.05);">
            © {{ now()->year }}: Organization Name -
            <span class="text-body">Current Hash: {{ \App\Helpers\GitHelper::getCommitHash() }}</span>
        </div> -->
    </footer>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-validate/1.19.0/jquery.validate.js"></script>
    <script src="https://cdn.datatables.net/1.11.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-MrcW6ZMFYlzcLA8Nl+NtUVF0sA7MsXsP1UyJoMp4YLEuNSfAP+JcXn/tWtIaxVXM"
        crossorigin="anonymous"></script>
    <script src="https://cdn.datatables.net/1.11.4/js/dataTables.bootstrap5.min.js"></script>

    <script src="https://cdn.datatables.net/buttons/2.2.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.2.2/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.2.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.2.2/js/buttons.print.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.2.2/js/buttons.colVis.min.js"></script>


    <script>
        $(document).ready(function () {
            $('.navbar-toggler').on('click', function () {
                $('body').toggleClass('menu-open');
            });

            $('.nav-link').on('click', function () {
                if ($(window).width() < 992) {
                    $('.navbar-collapse').removeClass('show');
                    $('body').removeClass('menu-open');
                }
            });
        });
    </script>
</body>

</html>
