@extends('layouts.app')

@section('title', $lable)

@section('content')
    @php($onlineCount = collect($log)->filter(fn ($device) => (int) $device->online === 1 || $device->online === true || $device->online === '1')->count())

    <div class="card mb-4">
        <div class="card-body">
            <div class="page-toolbar">
                <div>
                    <span class="eyebrow text-primary fw-bold text-uppercase small">Legacy Reports</span>
                    <h1 class="mt-2 mb-1">{{ $lable }}</h1>
                    <p class="small mb-0">Quick visibility into legacy device rows captured by the older reporting flow.</p>
                </div>
                <div class="toolbar-cluster">
                    <div class="stat-pill">
                        <span>Total Devices</span>
                        <strong>{{ count($log) }}</strong>
                    </div>
                    <div class="stat-pill">
                        <span>Online</span>
                        <strong>{{ $onlineCount }}</strong>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card table-shell">
        <div class="card-header">
            <div>
                <h5 class="shell-title">Device Status Table</h5>
                <div class="shell-subtitle">Serial number and online flag from the legacy reporting source.</div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="devices">
                    <thead>
                        <tr>
                            <th>Serial Number</th>
                            <th>Online</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($log as $d)
                            <tr>
                                <td><code>{{ $d->no_sn }}</code></td>
                                <td>
                                    <span class="badge bg-{{ (int) $d->online === 1 ? 'success' : 'secondary' }}">
                                        {{ (int) $d->online === 1 ? 'Online' : 'Offline' }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="2">
                                    <div class="empty-state">
                                        <i class="bi bi-hdd-network"></i>
                                        <div class="fw-semibold">No device rows available</div>
                                        <div class="small">Legacy device data will appear here when records are present in the source table.</div>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
