@extends('layouts.app')

@section('title', $lable)

@section('content')
    <div class="card mb-4">
        <div class="card-body">
            <div class="page-toolbar">
                <div>
                    <span class="eyebrow text-primary fw-bold text-uppercase small">Legacy Reports</span>
                    <h1 class="mt-2 mb-1">{{ $lable }}</h1>
                    <p class="small mb-0">Historical rows from the legacy logging tables with pagination support.</p>
                </div>
                <div class="stat-pill">
                    <span>Visible Rows</span>
                    <strong>{{ $log->count() }}</strong>
                </div>
            </div>
        </div>
    </div>

    <div class="card table-shell">
        <div class="card-header">
            <div>
                <h5 class="shell-title">Log Table</h5>
                <div class="shell-subtitle">Direct inspection of stored URL and payload content.</div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="devices">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Url</th>
                            <th>Data</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($log as $d)
                            <tr>
                                <td>{{ $d->id }}</td>
                                <td><code>{{ $d->url }}</code></td>
                                <td>{{ $d->data }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3">
                                    <div class="empty-state">
                                        <i class="bi bi-journal-text"></i>
                                        <div class="fw-semibold">No logs available</div>
                                        <div class="small">The selected legacy log source has not produced any rows yet.</div>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer">
            {{ $log->links('pagination::bootstrap-5') }}
        </div>
    </div>
@endsection
