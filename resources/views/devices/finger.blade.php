@extends('layouts.app')

@section('title', 'Finger Logs')

@section('content')
    <div class="card mb-4">
        <div class="card-body">
            <span class="eyebrow text-primary fw-bold text-uppercase small">Legacy Reports</span>
            <h1 class="mt-2 mb-1">Finger Logs</h1>
            <p class="small mb-0">Raw finger log payloads captured by the older reporting flow.</p>
        </div>
    </div>

    <div class="card table-shell">
        <div class="card-header">
            <div>
                <h5 class="shell-title">Finger Log Table</h5>
                <div class="shell-subtitle">Use this screen when debugging legacy finger-log callbacks.</div>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover mb-0 data-table" id="fingers-log">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Data</th>
                        </tr>
                    </thead>
                    <tbody>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
