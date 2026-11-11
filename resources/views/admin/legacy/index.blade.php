@extends('admin.layout')

@section('title', 'Original SQL Server Data')
@section('page_title', 'Original Attendance Database')
@section('page_subtitle', 'Direct, read-only access to Att2000_V4 on SQL Server. No copies, imports, or database schema changes.')

@section('content')
    <div class="alert alert-info">
        <i class="bi bi-shield-lock me-2"></i>
        <strong>SQL Server source — read-only.</strong>
        These are original historical records, not imports into MySQL.
        Device command queues and new ADMS uploads still use the existing operational MySQL database.
        Listed devices are historical registrations, not confirmation they are online.
    </div>

    <div class="metric-grid">
        @foreach (['employees' => 'Employees', 'attendance' => 'Attendance records', 'devices' => 'Registered devices', 'templates' => 'Biometric templates'] as $key => $title)
            <div class="card metric-card">
                <div class="card-body">
                    <div class="metric-eyebrow">{{ $title }}</div>
                    <div class="metric-value">{{ number_format($totals[$key]) }}</div>
                    <div class="metric-note">Original SQL Server table</div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card">
        <div class="card-body">
            <nav class="nav nav-pills gap-2 mb-3" aria-label="Original database tables">
                @foreach (['employees' => 'Employees', 'attendance' => 'Attendance', 'devices' => 'Devices', 'templates' => 'Template metadata'] as $key => $label)
                    <a href="{{ route('admin.legacy.index', ['tab' => $key]) }}"
                       class="nav-link {{ $tab === $key ? 'active' : '' }}">{{ $label }}</a>
                @endforeach
            </nav>

            <form action="{{ route('admin.legacy.index') }}" method="GET" class="row g-3">
                <input type="hidden" name="tab" value="{{ $tab }}">
                <div class="col-lg-{{ $tab === 'attendance' ? '4' : '9' }}">
                    <label class="form-label" for="legacy-search">
                        {{ $tab === 'devices' ? 'Serial or alias' : ($tab === 'attendance' ? 'Badge or device serial' : ($tab === 'templates' ? 'Badge number' : 'Badge number or name')) }}
                    </label>
                    <input id="legacy-search" type="search" name="search" maxlength="80"
                           class="form-control" value="{{ $search }}" placeholder="Filter original records">
                </div>
                @if ($tab === 'attendance')
                    <div class="col-lg-2">
                        <label class="form-label" for="legacy-from">From date</label>
                        <input type="date" id="legacy-from" name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}">
                    </div>
                    <div class="col-lg-2">
                        <label class="form-label" for="legacy-to">To date</label>
                        <input type="date" id="legacy-to" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}">
                    </div>
                @endif
                <div class="col-lg-3 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="{{ route('admin.legacy.index', ['tab' => $tab]) }}" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    @if ($tab === 'templates')
        <div class="card">
            <div class="card-header"><h5 class="shell-title">Template types and formats</h5></div>
            <div class="card-body">
                <p class="small text-muted mb-2">Counts only. No fingerprint or face payloads are read from SQL Server.</p>
                @forelse ($templateStats as $stat)
                    <span class="badge bg-secondary me-2 mb-2">
                        Type {{ $stat->TemplateType ?? 'NULL' }} · Format {{ $stat->Format ?? 'NULL' }}: {{ number_format($stat->total) }}
                    </span>
                @empty
                    <p class="text-muted">No template metadata found.</p>
                @endforelse
            </div>
        </div>
    @endif

    <div class="card table-shell">
        <div class="card-header">
            <div>
                <h5 class="shell-title">Original {{ ucfirst($tab) }} table</h5>
                <div class="shell-subtitle">Showing a paginated view of original SQL Server rows; no records modified.</div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            @if ($tab === 'employees')
                                <th>Source ID</th><th>Badge number</th><th>Name</th><th>Department ID</th><th>Card number</th><th>Hired</th>
                            @elseif ($tab === 'attendance')
                                <th>Source ID</th><th>Badge</th><th>Time</th><th>Device serial</th><th>Verification</th><th>Status</th>
                            @elseif ($tab === 'devices')
                                <th>Source ID</th><th>Serial</th><th>Alias</th><th>Model</th><th>Source status</th><th>IP (historical)</th><th>Protocol</th>
                            @else
                                <th>Source ID</th><th>Badge</th><th>Type</th><th>Index</th><th>Number</th><th>Format</th><th>Version</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr>
                                @if ($tab === 'employees')
                                    <td>{{ $row->Id }}</td>
                                    <td><code>{{ $row->BadgeNumber ?: 'MISSING' }}</code></td>
                                    <td>{{ $row->NAME ?: 'Name unavailable' }}</td>
                                    <td>{{ $row->DepartmentId ?? '—' }}</td>
                                    <td>{{ $row->CardNo ?: '—' }}</td>
                                    <td>{{ $row->HiredDay ? \Illuminate\Support\Carbon::parse($row->HiredDay)->format('Y-m-d') : '—' }}</td>
                                @elseif ($tab === 'attendance')
                                    <td>{{ $row->Id }}</td>
                                    <td><code>{{ $row->BadgeNumber ?: 'MISSING' }}</code></td>
                                    <td>{{ $row->VerifyTime ? \Illuminate\Support\Carbon::parse($row->VerifyTime)->format('Y-m-d H:i:s') : '—' }}</td>
                                    <td><code>{{ $row->DeviceSerialNumber ?: '—' }}</code></td>
                                    <td>{{ $row->VerifyType ?: '—' }}</td>
                                    <td>{{ $row->Status ?: '—' }}</td>
                                @elseif ($tab === 'devices')
                                    <td>{{ $row->Id }}</td>
                                    <td><code>{{ $row->DeviceSerialNumber ?: '—' }}</code></td>
                                    <td>{{ $row->DeviceAliasName ?: '—' }}</td>
                                    <td>{{ $row->DeviceName ?: '—' }}</td>
                                    <td>{{ $row->DeviceStatus ?? '—' }}</td>
                                    <td><code>{{ $row->DeviceIP ?: '—' }}</code></td>
                                    <td>{{ $row->push_protocol ?: '—' }}</td>
                                @else
                                    <td>{{ $row->Id }}</td>
                                    <td><code>{{ $row->BadgeNumber ?: 'MISSING' }}</code></td>
                                    <td>{{ $row->TemplateType ?? '—' }}</td>
                                    <td>{{ $row->TemplateIndex ?? '—' }}</td>
                                    <td>{{ $row->TemplateNo ?? '—' }}</td>
                                    <td>{{ $row->Format ?? '—' }}</td>
                                    <td>{{ $row->MajorVer ?? '—' }}.{{ $row->MinorVer ?? '—' }}</td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">No matching historical rows found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer">
            {{ $rows->links('pagination::simple-bootstrap-5') }}
        </div>
    </div>
@endsection
