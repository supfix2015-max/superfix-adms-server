@extends('admin.layout')

@section('title', 'Device Connectivity (SQL Server)')
@section('page_title', 'Device Connectivity')
@section('page_subtitle', 'Historical SQL Server device registrations compared with real Laravel ADMS contact timestamps.')

@section('page_actions')
    <a href="{{ route('admin.legacy.index', ['tab' => 'devices']) }}" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-database me-1"></i> Original device records
    </a>
@endsection

@section('content')
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-1"></i>
        <strong>Read-only monitoring.</strong>
        Original device registrations and past punches do not establish online status.
        Online is counted only when the ADMS app recorded a heartbeat or request within the previous {{ $ttl }} minutes.
        No devices, employees, fingerprints or commands are modified here.
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="card h-100"><div class="card-body">
            <div class="text-secondary small fw-semibold text-uppercase">Historically registered terminals</div>
            <div class="fs-2 fw-bold">{{ number_format($historicalCount) }}</div>
            <div class="small text-secondary">Att2000_V4.dbo.Device</div>
        </div></div></div>
        <div class="col-md-4"><div class="card h-100"><div class="card-body">
            <div class="text-secondary small fw-semibold text-uppercase">Live verified by inbound ADMS</div>
            <div class="fs-2 fw-bold">{{ number_format($onlineCount) }}</div>
            <div class="small text-secondary">Based on app last_seen; not historical device status</div>
        </div></div></div>
        <div class="col-md-4"><div class="card h-100"><div class="card-body">
            <div class="text-secondary small fw-semibold text-uppercase">No recent confirmed connection</div>
            <div class="fs-2 fw-bold">{{ number_format($historicalCount - $onlineCount) }}</div>
            <div class="small text-secondary">May still be active on a different server</div>
        </div></div></div>
    </div>

    <div class="card table-shell">
        <div class="card-header">
            <div>
                <h5 class="shell-title">Original registered devices and observed connectivity</h5>
                <div class="shell-subtitle">Historical activity is cached for two minutes. Inbound contact times are queried live from adms.devices.</div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Device</th>
                            <th>Serial</th>
                            <th>Historical last punch</th>
                            <th>Last ADMS contact</th>
                            <th>Connection</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($rows as $device)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $device['name'] }}</div>
                                @if ($device['legacy_ip'])
                                    <div class="small text-secondary">Historical IP: {{ $device['legacy_ip'] }}</div>
                                @endif
                            </td>
                            <td><code>{{ $device['serial'] ?: '—' }}</code></td>
                            <td class="text-nowrap">{{ $device['last_historical_punch_formatted'] ?: 'No records' }}</td>
                            <td class="text-nowrap">{{ $device['last_contact'] ?: 'Never received by this application' }}</td>
                            <td>
                                @if ($device['online'])
                                    <span class="badge text-bg-success">Receiving now</span>
                                @elseif ($device['registered_in_app'])
                                    <span class="badge text-bg-warning">No recent contact</span>
                                @else
                                    <span class="badge text-bg-secondary">Not registered in this app</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-muted text-center py-4">No original device registrations found.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="small text-secondary mt-3">
        Device and punch timestamps are shown in {{ $timeZone }}. No device commands are dispatched by opening this screen.
    </div>
@endsection
