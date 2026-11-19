@extends('admin.layout')

@section('title', 'ADMS · ZenHR Integration')
@section('page_title', 'ZenHR Integration')
@section('page_subtitle', 'AT1 receives device punches over HTTP; AT2 provides the HTTPS console, ZenHR OAuth and source attendance outbox.')

@section('content')
<div class="row g-4">
    <div class="col-xl-8">
        <div class="card shadow-sm p-4">
            <div class="d-flex justify-content-between gap-2 align-items-center mb-3">
                <h5 class="mb-0"><i class="bi bi-cloud-check me-2"></i>ZenHR OAuth</h5>
                <span class="badge {{ $connection ? 'bg-success' : 'bg-secondary' }}">
                    {{ $connection ? 'Connected' : 'Not Connected' }}
                </span>
            </div>

            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <tbody>
                        <tr><th>Integration environment</th><td>AT2 · at2.superfix.ae</td></tr>
                        <tr><th>Application</th><td>Superfix ADMS Enterprise (separate from legacy Attendance API)</td></tr>
                        <tr><th>Callback URI</th><td><code>https://at2.superfix.ae/admin/integrations/zenhr/callback</code></td></tr>
                        <tr><th>OAuth credentials</th><td>{{ $credentialsReady ? 'Configured in AT2 environment' : 'Missing in AT2 environment' }}</td></tr>
                        <tr><th>Encrypted SQL storage</th><td>{{ $storageReady ? 'Available · adms.zenhr_connections' : 'DBA setup required · docs/sql/adms-zenhr-oauth-storage.sql' }}</td></tr>
                        <tr><th>Connected company ID</th><td>{{ $connection?->company_id ?? '—' }}</td></tr>
                        <tr><th>Token expiry (UTC)</th><td>{{ $connection?->token_expires_at ?? '—' }}</td></tr>
                        <tr><th>Last read-only verification (UTC)</th><td>{{ $connection?->last_verified_at ?? '—' }}</td></tr>
                        <tr><th>Attendance sync</th><td><strong>{{ $autoSendConfigured ? 'Automatic sending configured' : 'Automatic sending not configured' }}</strong></td></tr>
                    </tbody>
                </table>
            </div>

            <div class="d-flex flex-wrap gap-2 mt-2">
                <form method="POST" action="{{ route('admin.zenhr.connect') }}">
                    @csrf
                    <button type="submit" class="btn btn-primary"
                            @disabled(! $credentialsReady || ! $storageReady)>
                        <i class="bi bi-box-arrow-up-right me-1"></i> Connect ZenHR
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.zenhr.test') }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary" @disabled(! $connection)>
                        <i class="bi bi-shield-check me-1"></i> Test Connection (Read-Only)
                    </button>
                </form>
            </div>
            @unless ($credentialsReady && $storageReady)
                <div class="alert alert-warning mt-3 mb-0">
                    Complete AT2-only OAuth variables and have a SQL Server administrator provision the
                    dedicated <code>adms.zenhr_connections</code> table before authorizing.
                </div>
            @endunless
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card shadow-sm p-4">
            <h6>Approved OAuth scopes</h6>
            <div class="small text-secondary mb-3">The new AT2 application needs only these requested permissions.</div>
            <ul class="small ps-3 mb-3">
                <li><code>read:employee</code></li>
                <li><code>read:branch</code></li>
                <li><code>read:attendance_record</code></li>
                <li><code>create:attendance_detail</code></li>
                <li><code>bulk_insert:attendance_detail</code></li>
            </ul>
            <div class="alert alert-info small mb-0">
                Legacy AT1 API credentials are not used. Device ATTLOG reception, current SQL attendance,
                and hourly clock sync continue independently. No local employee mapping. Outbound delivery requires a verified ZenHR branch and POST body contract.
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="card shadow-sm p-4">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-3 flex-wrap">
                <h5 class="mb-0"><i class="bi bi-arrow-left-right me-2"></i>Attendance Outbox (Nov 12 onward)</h5>
                <span class="badge {{ $autoSendConfigured ? 'bg-success' : 'bg-secondary' }}">{{ $autoSendConfigured ? 'Auto-send configured · not a worker health check' : 'Auto-send not configured' }}</span>
            </div>
            <p class="text-secondary small mb-3">
                AT1 (HTTP) receives ZKTeco ATTLOG and answers commands; AT2 (HTTPS) collects from the authoritative
                <code>dbo.Attendance</code> ledger for sending. The outbox can also contain historical staged records.
                A pending row is not necessarily eligible for delivery.
            </p>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <tbody>
                        <tr><th>Source start (Dubai)</th><td>2026-11-12 00:00:00 onward</td></tr>
                        <tr><th>Outbox SQL</th><td>{{ $outboxStats['ready'] ? 'Ready · adms.zenhr_attendance_outbox' : 'Needs DBA setup · docs/sql/adms-zenhr-attendance-outbox.sql' }}</td></tr>
                        <tr><th>Automatic collection</th><td>{{ $autoCollect ? 'Configured on AT2 (after daemon restart)' : 'OFF · set ZENHR_OUTBOX_AUTO_COLLECT=true on AT2 after SQL setup' }}</td></tr>
                        <tr><th>Queued (not delivered)</th><td><strong>{{ number_format((int) ($outboxStats['counts']['pending'] ?? 0)) }}</strong></td></tr>
                        <tr><th>Needs review</th><td><strong>{{ number_format((int) ($outboxStats['counts']['needs_review'] ?? 0)) }}</strong> (including Hili clock repair period)</td></tr>
                        <tr><th>Accepted by ZenHR API</th><td>{{ number_format((int) ($outboxStats['counts']['sent'] ?? 0)) }}</td></tr>
                        <tr><th>Original SQL cursor</th><td>{{ $outboxStats['cursors']?->last_dbo_id ?? '—' }}</td></tr>
                        <tr><th>Staged ATTLOG cursor</th><td>{{ $outboxStats['cursors']?->last_adms_id ?? '—' }}</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="alert alert-warning mb-0 small">
                A connected OAuth account is not proof of attendance delivery.
                HTTP 202 indicates API acceptance, not confirmation of each punch in the ZenHR UI.
                This screen reports stored outbox states separately from the worker configuration.
            </div>
        </div>
    </div>
    <div class="col-12" id="pending-attendance">
        <div class="card shadow-sm p-4">
            <div class="row g-3 mb-4">
                @foreach (['sent' => ['API accepted', 'success'], 'pending' => ['Pending', 'warning'], 'needs_review' => ['Needs review', 'secondary']] as $state => [$label, $color])
                    <div class="col-md-4">
                        <div class="border rounded p-3">
                            <div class="text-secondary small">{{ $label }}</div>
                            <div class="fs-3 fw-bold text-{{ $color }}">{{ $outboxStats['ready'] ? number_format((int) ($outboxStats['counts'][$state] ?? 0)) : '—' }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
            @if (array_sum(array_map(fn ($state) => (int) ($outboxStats['counts'][$state] ?? 0), ['inflight', 'unknown', 'failed'])) > 0)
                <div class="alert alert-warning small">Unconfirmed or rejected events: {{ number_format((int) ($outboxStats['counts']['inflight'] ?? 0)) }} inflight, {{ number_format((int) ($outboxStats['counts']['unknown'] ?? 0)) }} unknown, {{ number_format((int) ($outboxStats['counts']['failed'] ?? 0)) }} failed. Investigate before restarting delivery.</div>
            @endif
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Pending Attendance</h5>
                <a class="btn btn-sm btn-outline-primary" href="{{ request()->fullUrl() }}#pending-attendance"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</a>
            </div>
            <p class="small text-secondary">Read-only queue from SQL Server, oldest punch first. Pending does not guarantee eligibility for ZenHR delivery. Employee and device names appear only for unique matches in the original directory. All punch times are Dubai local time.</p>
            <form method="GET" action="{{ route('admin.zenhr.index') }}#pending-attendance" class="d-flex flex-wrap align-items-end gap-2 mb-3">
                <div class="flex-grow-1">
                    <label for="pending-search" class="form-label small">Search badge, employee, device serial or alias</label>
                    <input id="pending-search" name="pending_search" type="search" class="form-control" maxlength="80" value="{{ $pendingSearch }}" placeholder="Employee or branch/device">
                </div>
                <button type="submit" class="btn btn-primary">Search</button>
                <a class="btn btn-outline-secondary" href="{{ route('admin.zenhr.index') }}#pending-attendance">Clear</a>
            </form>
            @if ($pendingRows === null)
                <div class="alert alert-warning mb-0">Outbox storage is not provisioned.</div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle">
                        <thead><tr><th>Badge</th><th>Employee</th><th>Branch / device alias</th><th>Device serial</th><th>Punch time (Dubai)</th><th>Direction</th><th>Source</th><th>Attempts</th><th>Status</th></tr></thead>
                        <tbody>
                            @forelse ($pendingRows as $row)
                                <tr>
                                    <td><code>{{ $row->badge_number }}</code></td>
                                    <td>{{ $row->employee_name ?: '—' }}</td>
                                    <td>{{ $row->device_alias ?: '—' }}</td>
                                    <td><code>{{ $row->device_serial ?: '—' }}</code></td>
                                    <td class="text-nowrap">{{ substr((string) $row->punched_at_local, 0, 19) }}</td>
                                    <td>{{ $row->direction ?: '—' }}</td>
                                    <td>{{ $row->source_kind }}</td>
                                    <td>{{ (int) $row->attempts }} @if ($row->last_error_code)<div class="small text-secondary">{{ $row->last_error_code }}</div>@endif</td>
                                    <td><span class="badge bg-warning text-dark">Pending</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="text-center text-secondary py-4">No pending punches on this page.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $pendingRows->fragment('pending-attendance')->links('pagination::simple-bootstrap-5') }}
            @endif
        </div>
    </div>
</div>
@endsection
