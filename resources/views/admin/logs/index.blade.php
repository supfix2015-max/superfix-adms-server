@extends('admin.layout')

@section('title', 'System Logs')
@section('page_title', 'Logs & Raw Data')
@section('content')

<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Raw Logs</h6>
                <a href="#raw-logs-tab" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body">
                <div class="row text-center g-3">
                    <div class="col-6">
                        <div class="h5 mb-0">{{ $rawStats['today'] ?? 0 }}</div>
                        <small class="text-muted">Today</small>
                    </div>
                    <div class="col-6">
                        <div class="h5 mb-0 text-warning">{{ $rawStats['pending'] ?? 0 }}</div>
                        <small class="text-muted">Pending</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Error Logs</h6>
                <a href="#error-logs-tab" class="btn btn-sm btn-outline-danger">View All</a>
            </div>
            <div class="card-body">
                <div class="row text-center g-3">
                    <div class="col-6">
                        <div class="h5 mb-0 text-danger">{{ $errorStats['today'] ?? 0 }}</div>
                        <small class="text-muted">Today</small>
                    </div>
                    <div class="col-6">
                        <div class="h5 mb-0">{{ $errorStats['unresolved'] ?? 0 }}</div>
                        <small class="text-muted">Unresolved</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<ul class="nav nav-tabs" id="logsTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="raw-logs-tab" data-bs-toggle="tab" data-bs-target="#raw-logs" type="button" role="tab">Raw Logs</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="error-logs-tab" data-bs-toggle="tab" data-bs-target="#error-logs" type="button" role="tab">Error Logs</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="commands-tab" data-bs-toggle="tab" data-bs-target="#commands" type="button" role="tab">Device Commands</button>
    </li>
</ul>

<div class="tab-content mt-3">
    {{-- Raw Logs --}}
    <div class="tab-pane fade show active" id="raw-logs" role="tabpanel">
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Device</th>
                                <th>Endpoint</th>
                                <th>Status</th>
                                <th>Records</th>
                                <th>Received</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rawLogs ?? [] as $log)
                                <tr class="{{ $log->processing_status === 'failed' ? 'table-danger' : ($log->processing_status === 'processing' ? 'table-warning' : '') }}">
                                    <td>{{ $log->device?->name ?? 'Unknown Device' }}</td>
                                    <td>
                                        <code class="small">{{ $log->endpoint }}</code>
                                    </td>
                                    <td>
                                        <span class="badge bg-{{ $log->processing_status === 'processed' ? 'success' : ($log->processing_status === 'failed' ? 'danger' : 'warning') }}">
                                            {{ ucfirst($log->processing_status) }}
                                        </span>
                                    </td>
                                    <td><strong>{{ $log->record_count }}</strong></td>
                                    <td>
                                        <small class="text-muted">{{ $log->created_at->diffForHumans() }}</small>
                                    </td>
                                    <td>
                                        <a href="#" class="btn btn-sm btn-outline-primary" onclick="viewRawLog({{ $log->id }})">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">
                                        No raw logs found
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- Error Logs --}}
    <div class="tab-pane fade" id="error-logs" role="tabpanel">
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Device</th>
                                <th>Type</th>
                                <th>Severity</th>
                                <th>Message</th>
                                <th>Time</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($errorLogs ?? [] as $error)
                                <tr class="table-{{ $error->severity === 'critical' ? 'danger' : ($error->severity === 'warning' ? 'warning' : 'secondary') }}">
                                    <td>{{ $error->device?->name ?? 'System' }}</td>
                                    <td><code>{{ $error->type }}</code></td>
                                    <td>
                                        <span class="badge bg-{{ $error->severity }}">{{ ucfirst($error->severity) }}</span>
                                    </td>
                                    <td class="small">{{ Str::limit($error->message, 50) }}</td>
                                    <td>
                                        <small>{{ $error->created_at->diffForHumans() }}</small>
                                    </td>
                                    <td>
                                        @if(!$error->resolved)
                                            <span class="badge bg-warning">Unresolved</span>
                                        @else
                                            <span class="badge bg-success">Resolved</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">
                                        No errors found
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- Device Commands --}}
    <div class="tab-pane fade" id="commands" role="tabpanel">
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <h6 class="mb-0">Device Commands</h6>
                <a href="{{ route('admin.commands.create') }}" class="btn btn-sm btn-success">Send Command</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Device</th>
                                <th>Command</th>
                                <th>Status</th>
                                <th>Sent</th>
                                <th>Executed</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($commands ?? [] as $command)
                                <tr>
                                    <td>{{ $command->device?->name ?? 'Unknown Device' }}</td>
                                    <td><code>{{ $command->command }}</code></td>
                                    <td>
                                        <span class="badge bg-{{ $command->status === 'pending' ? 'warning' : ($command->status === 'done' ? 'success' : 'danger') }}">
                                            {{ ucfirst($command->status) }}
                                        </span>
                                    </td>
                                    <td>{{ $command->created_at->diffForHumans() }}</td>
                                    <td>{{ $command->executed_at?->diffForHumans() ?? '-' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">No commands</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Raw Log Modal --}}
<div class="modal fade" id="rawLogModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Raw Payload</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <pre id="rawPayloadContent" class="bg-dark text-light p-3 rounded" style="font-size: 0.875rem; max-height: 500px; overflow-y: auto;"></pre>
            </div>
        </div>
    </div>
</div>

@push('extra_js')
<script>
function viewRawLog(logId) {
    fetch(`/admin/logs/{{ $log->id }}/raw`)  // TODO: Add route
        .then(response => response.text())
        .then(data => {
            document.getElementById('rawPayloadContent').textContent = data;
            new bootstrap.Modal(document.getElementById('rawLogModal')).show();
        });
}
</script>
@endpush

@endsection
