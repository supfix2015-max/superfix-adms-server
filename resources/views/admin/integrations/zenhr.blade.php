@extends('admin.layout')

@section('title', 'ADMS · ZenHR Integration')
@section('page_title', 'ZenHR Integration')
@section('page_subtitle', 'AT2-only OAuth connection for Superfix ADMS Enterprise. Attendance delivery remains disabled until employee mapping is verified.')

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
                and hourly clock sync continue independently. Next phase: employee/branch mapping,
                duplicate screening, and a one-punch controlled pilot.
            </div>
        </div>
    </div>
</div>
@endsection
