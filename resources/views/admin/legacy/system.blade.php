@extends('admin.layout')
@section('title','ADMS · System')
@section('page_title','System Operations')
@section('content')
<div class="row g-3">
    <div class="col-md-6">
        <div class="card p-3">
            <h5>Connection configuration</h5>
            <div class="d-flex justify-content-between border-bottom py-2">
                <span>Original source database</span><strong>Att2000_V4.dbo</strong>
            </div>
            <div class="d-flex justify-content-between border-bottom py-2">
                <span>Operational ADMS data</span><strong>Att2000_V4.adms</strong>
            </div>
            <div class="d-flex justify-content-between border-bottom py-2">
                <span>Device timezone</span><strong>Asia/Dubai (UTC+04)</strong>
            </div>
            <div class="d-flex justify-content-between border-bottom py-2">
                <span>Command dispatch</span>
                <strong>{{ config('zkteco.dispatch_commands', false) ? 'Enabled — authenticated token required' : 'Disabled — safe' }}</strong>
            </div>
            <div class="d-flex justify-content-between py-2">
                <span>Legacy time sync</span>
                <strong>{{ config('zkteco.legacy_time_only_enabled', false) ? 'Enabled' : 'Disabled' }}</strong>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card p-3">
            <h5>Operations</h5>
            <div class="d-grid gap-2">
                <a href="{{ route('admin.fleet.index') }}" class="btn btn-outline-success">Device Fleet · Edit License flags</a>
                <a href="{{ route('admin.device-connectivity.index') }}" class="btn btn-outline-secondary">View device connectivity</a>
                <a href="{{ route('admin.commands.index') }}" class="btn btn-outline-secondary">Command queue status</a>
                <a href="{{ route('admin.logs.index') }}" class="btn btn-outline-secondary">ADMS request logs</a>
                <a href="{{ route('admin.legacy.index', ['tab'=>'templates']) }}" class="btn btn-outline-secondary">Biometric template metadata (no payload)</a>
            </div>
        </div>
    </div>
</div>
<div class="alert alert-warning mt-3">
    Vendor <strong>Edit License</strong> is tracked as a per-device checkbox only. Installing a license key onto a physical terminal
    requires a separately verified vendor protocol; no secret license key is stored or transmitted by this UI.
</div>
@endsection
