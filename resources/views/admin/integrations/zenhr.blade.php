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
                        <tr><th>Attendance sync</th><td><strong>Disabled — no punches are sent</strong></td></tr>
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
                <span class="badge bg-warning text-dark">ZenHR sending OFF · Not yet delivered</span>
            </div>
            <p class="text-secondary small mb-3">
                AT1 (HTTP) receives ZKTeco ATTLOG and answers commands; AT2 (HTTPS) reads both original
                <code>dbo.Attendance</code> and <code>adms.attendance_logs</code>. The collector
                deduplicates by device, badge, timestamp and punch function. No employee matching is performed.
            </p>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <tbody>
                        <tr><th>Source start (Dubai)</th><td>2026-11-12 00:00:00 onward</td></tr>
                        <tr><th>Outbox SQL</th><td>{{ $outboxStats['ready'] ? 'Ready · adms.zenhr_attendance_outbox' : 'Needs DBA setup · docs/sql/adms-zenhr-attendance-outbox.sql' }}</td></tr>
                        <tr><th>Automatic collection</th><td>{{ $autoCollect ? 'Configured on AT2 (after daemon restart)' : 'OFF · set ZENHR_OUTBOX_AUTO_COLLECT=true on AT2 after SQL setup' }}</td></tr>
                        <tr><th>Queued (not delivered)</th><td><strong>{{ number_format((int) ($outboxStats['counts']['pending'] ?? 0)) }}</strong></td></tr>
                        <tr><th>Needs review</th><td><strong>{{ number_format((int) ($outboxStats['counts']['needs_review'] ?? 0)) }}</strong> (including Hili clock repair period)</td></tr>
                        <tr><th>Confirmed sent</th><td>{{ number_format((int) ($outboxStats['counts']['sent'] ?? 0)) }}</td></tr>
                        <tr><th>Original SQL cursor</th><td>{{ $outboxStats['cursors']?->last_dbo_id ?? '—' }}</td></tr>
                        <tr><th>Staged ATTLOG cursor</th><td>{{ $outboxStats['cursors']?->last_adms_id ?? '—' }}</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="alert alert-warning mb-0 small">
                A connected OAuth account is not proof of attendance delivery.
                The vendor POST payload and branch context must be verified before enabling external sends,
                and already-delivered records from the old integration must be excluded.
                This screen intentionally reports source collection separately from ZenHR delivery.
            </div>
        </div>
    </div>
</div>
@endsection
