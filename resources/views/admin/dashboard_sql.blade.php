@extends('admin.layout')

@section('title', 'SQL Server Dashboard')
@section('page_title', 'Attendance Overview')
@section('page_subtitle', 'Verified statistics from the original Att2000_V4 SQL Server database. Historical source data only; no staged MySQL imports.')

@section('page_actions')
    <a href="{{ route('admin.legacy.index') }}" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-database me-1"></i> Browse Original Database
    </a>
    <a href="{{ route('admin.device-connectivity.index') }}" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-broadcast me-1"></i> Device Connectivity
    </a>
    <a href="{{ route('admin.commands.create') }}" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-terminal me-1"></i> Device Commands
    </a>
@endsection

@section('extra_css')
    <style>
        .legacy-dashboard .source-meta { display:flex; flex-wrap:wrap; gap:.65rem; align-items:center; }
        .legacy-dashboard .source-pill { background:#edf4ff; color:#264b72; border-radius:99px; padding:.45rem .75rem; font-weight:600; font-size:.8rem; }
        .legacy-dashboard .snapshot-kpi { border:1px solid #e7edf5; border-radius:14px; padding:1rem; background:#fff; min-height:108px; }
        .legacy-dashboard .snapshot-kpi .label { font-size:.76rem; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
        .legacy-dashboard .snapshot-kpi .value { margin-top:.4rem; font-size:1.7rem; color:#13335b; font-weight:800; }
        .legacy-dashboard .trend { display:grid; grid-template-columns:repeat(7,minmax(0,1fr)); align-items:end; gap:12px; height:205px; margin-top:1.4rem; }
        .legacy-dashboard .trend-slot { display:flex; flex-direction:column; align-items:center; justify-content:end; height:100%; min-width:0; }
        .legacy-dashboard .trend-track { height:145px; width:100%; max-width:52px; display:flex; align-items:flex-end; border-radius:9px; background:#f1f6fb; overflow:hidden; }
        .legacy-dashboard .trend-bar { background:linear-gradient(180deg,#348ae5,#1859aa); width:100%; border-radius:9px 9px 0 0; min-height:2px; }
        .legacy-dashboard .trend-total { font-size:.77rem; font-weight:700; color:#34546c; }
        .legacy-dashboard .trend-date { color:#64748b; font-size:.72rem; white-space:nowrap; }
        .legacy-dashboard .readonly-tag { border:1px solid #dae5f2; color:#3f5a76; padding:.2rem .45rem; font-size:.7rem; border-radius:6px; }
        .legacy-dashboard .table-shell .table { font-size:.86rem; }
        @media (max-width:600px) {
            .legacy-dashboard .trend { gap:5px; }
            .legacy-dashboard .trend-date { font-size:.64rem; }
            .legacy-dashboard .trend-total { font-size:.7rem; }
        }
    </style>
@endsection

@section('content')
<div class="legacy-dashboard">
    <div class="alert {{ $lastDataIsStale ? 'alert-warning' : 'alert-info' }}">
        <i class="bi {{ $lastDataIsStale ? 'bi-exclamation-triangle' : 'bi-shield-check' }} me-2"></i>
        <strong>Original SQL Server — read only.</strong>
        @if ($latestAt)
            Last recorded punch: <strong>{{ $latestAt->format('d M Y, H:i:s') }}</strong>
            ({{ $sourceTimeZone }}).
            @if ($lastDataIsStale)
                This database has no recent punches. Historical registration is not proof of live device connection.
            @endif
        @else
            No timestamped attendance has been found in the original database.
        @endif
        <div class="small mt-1">Values below come from Att2000_V4, not from MySQL staging. Summaries are cached for up to five minutes; no employee or biometric records are modified.</div>
    </div>

    <div class="metric-grid">
        @foreach ([
            ['label'=>'Original employees', 'value'=>$totals['employees'], 'subtitle'=>'SQL Server Employee', 'icon'=>'bi-people'],
            ['label'=>'All-time punches', 'value'=>$totals['attendance'], 'subtitle'=>'SQL Server Attendance', 'icon'=>'bi-calendar2-check'],
            ['label'=>'Registered terminals', 'value'=>$totals['devices'], 'subtitle'=>'Historical records, not online count', 'icon'=>'bi-hdd-network'],
            ['label'=>'Biometric templates', 'value'=>$totals['templates'], 'subtitle'=>'Count only — no template content', 'icon'=>'bi-fingerprint']
        ] as $item)
            <div class="card metric-card">
                <div class="card-body">
                    <div class="metric-eyebrow">{{ $item['label'] }}</div>
                    <div class="metric-value">{{ number_format($item['value']) }}</div>
                    <div class="metric-note">
                        <i class="bi {{ $item['icon'] }}"></i>
                        {{ $item['subtitle'] }}
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card table-shell">
        <div class="card-header">
            <div>
                <h5 class="shell-title">Latest day in the original database</h5>
                <div class="shell-subtitle">These numbers refer to the latest day with records, not necessarily today.</div>
            </div>
            @if ($latestAt)
                <span class="source-pill">{{ $latestAt->format('d M Y') }}</span>
            @endif
        </div>
        <div class="card-body">
            <div class="row g-3">
                @foreach ([
                    ['label'=>'Punches recorded', 'value'=>$daily['total']],
                    ['label'=>'Check-ins', 'value'=>$daily['checkins']],
                    ['label'=>'Check-outs', 'value'=>$daily['checkouts']],
                    ['label'=>'Distinct badges', 'value'=>$daily['unique_employees']],
                ] as $item)
                    <div class="col-6 col-lg-3">
                        <div class="snapshot-kpi">
                            <div class="label">{{ $item['label'] }}</div>
                            <div class="value">{{ number_format($item['value']) }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-7">
            <div class="card table-shell h-100">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">Attendance trend</h5>
                        <div class="shell-subtitle">Seven calendar days ending on the most recent SQL Server attendance date.</div>
                    </div>
                    <span class="readonly-tag"><i class="bi bi-lock me-1"></i> Source records</span>
                </div>
                <div class="card-body">
                    @if (count($trend))
                        @php($maxTrend = max(1, ...array_column($trend, 'count')))
                        <div class="trend" role="img" aria-label="Attendance volume for the last seven recorded calendar dates">
                            @foreach ($trend as $point)
                                <div class="trend-slot" title="{{ $point['date'] }}: {{ number_format($point['count']) }} punches">
                                    <div class="trend-total">{{ number_format($point['count']) }}</div>
                                    <div class="trend-track">
                                        <div class="trend-bar" style="height:{{ $point['count'] > 0 ? max(2, round($point['count'] / $maxTrend * 100)) : 0 }}%"></div>
                                    </div>
                                    <div class="trend-date">{{ $point['day'] }}</div>
                                    <div class="trend-date">{{ \Carbon\CarbonImmutable::parse($point['date'])->format('d M') }}</div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-muted mb-0">No attendance history available to chart.</p>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="card table-shell h-100">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">Punch types on latest day</h5>
                        <div class="shell-subtitle">Distribution from original Attendance.Status.</div>
                    </div>
                </div>
                <div class="card-body">
                    @forelse ($statusBreakdown as $item)
                        <div class="mb-3">
                            <div class="d-flex justify-content-between small mb-1">
                                <span class="fw-semibold">{{ $item['label'] }}</span>
                                <span class="text-muted">{{ number_format($item['count']) }}</span>
                            </div>
                            <div class="progress" style="height:8px">
                                <div class="progress-bar" style="width:{{ $daily['total'] > 0 ? min(100, round($item['count'] / $daily['total'] * 100)) : 0 }}%"></div>
                            </div>
                        </div>
                    @empty
                        <p class="text-muted mb-0">No attendance statuses to show.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card table-shell h-100">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">Most recent original punches</h5>
                        <div class="shell-subtitle">Showing the latest 12 saved records in SQL Server; not live MySQL uploads.</div>
                    </div>
                    <a href="{{ route('admin.legacy.index', ['tab'=>'attendance']) }}" class="btn btn-outline-primary btn-sm">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead><tr><th>Badge</th><th>Date &amp; time</th><th>Type</th><th>Verification</th><th>Device serial</th></tr></thead>
                            <tbody>
                            @forelse ($recentPunches as $punch)
                                <tr>
                                    <td><code>{{ $punch->BadgeNumber ?: '—' }}</code></td>
                                    <td class="text-nowrap">{{ $punch->VerifyTime ? \Carbon\Carbon::parse($punch->VerifyTime)->format('d M Y H:i:s') : '—' }}</td>
                                    <td>{{ $punch->Status ?: '—' }}</td>
                                    <td>{{ $punch->VerifyType ?: '—' }}</td>
                                    <td><code>{{ $punch->DeviceSerialNumber ?: '—' }}</code></td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted py-4">No original attendance found.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card table-shell h-100">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">Historical devices</h5>
                        <div class="shell-subtitle">Registrations from SQL Server. Connection state is not monitored here.</div>
                    </div>
                    <a href="{{ route('admin.legacy.index', ['tab'=>'devices']) }}" class="btn btn-outline-primary btn-sm">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead><tr><th>Terminal</th><th>Serial</th></tr></thead>
                            <tbody>
                            @forelse ($registeredDevices as $device)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $device->DeviceAliasName ?: ($device->DeviceName ?: 'Unnamed') }}</div>
                                        <span class="readonly-tag">Registered historically</span>
                                    </td>
                                    <td><code>{{ $device->DeviceSerialNumber ?: '—' }}</code></td>
                                </tr>
                            @empty
                                <tr><td colspan="2" class="text-center text-muted py-4">No legacy devices registered.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
