@extends('admin.layout')

@section('title', 'System Logs')
@section('page_title', 'Logs And Raw Data')
@section('page_subtitle', 'Review inbound ADMS payloads, unresolved errors, and device command activity from a single diagnostic workspace.')

@section('page_actions')
    <a href="{{ route('admin.commands.create') }}" class="btn btn-success">
        <i class="bi bi-terminal-plus me-1"></i>
        Send Command
    </a>
@endsection

@section('content')
    @php
        $activeTab = $activeTab ?? 'raw';
    @endphp

    <div class="metric-grid">
        <div class="card metric-card">
            <div class="card-body">
                <div class="metric-eyebrow">Raw Logs Today</div>
                <div class="metric-value">{{ $rawStats['today'] ?? 0 }}</div>
                <div class="metric-note positive">
                    <i class="bi bi-cloud-arrow-down"></i>
                    inbound payloads
                </div>
            </div>
        </div>
        <div class="card metric-card">
            <div class="card-body">
                <div class="metric-eyebrow">Pending Raw Logs</div>
                <div class="metric-value">{{ $rawStats['pending'] ?? 0 }}</div>
                <div class="metric-note {{ ($rawStats['pending'] ?? 0) > 0 ? 'negative' : 'positive' }}">
                    <i class="bi bi-hourglass-split"></i>
                    waiting for processing
                </div>
            </div>
        </div>
        <div class="card metric-card">
            <div class="card-body">
                <div class="metric-eyebrow">Errors Today</div>
                <div class="metric-value">{{ $errorStats['today'] ?? 0 }}</div>
                <div class="metric-note {{ ($errorStats['today'] ?? 0) > 0 ? 'negative' : 'positive' }}">
                    <i class="bi bi-exclamation-octagon"></i>
                    diagnostics raised
                </div>
            </div>
        </div>
        <div class="card metric-card">
            <div class="card-body">
                <div class="metric-eyebrow">Unresolved Errors</div>
                <div class="metric-value">{{ $errorStats['unresolved'] ?? 0 }}</div>
                <div class="metric-note {{ ($errorStats['unresolved'] ?? 0) > 0 ? 'negative' : 'positive' }}">
                    <i class="bi bi-life-preserver"></i>
                    require attention
                </div>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs" id="logsTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link {{ $activeTab === 'raw' ? 'active' : '' }}" id="raw-logs-tab" data-bs-toggle="tab" data-bs-target="#raw-logs" type="button" role="tab" aria-controls="raw-logs" aria-selected="{{ $activeTab === 'raw' ? 'true' : 'false' }}" data-tab-key="raw">Raw Logs</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link {{ $activeTab === 'error' ? 'active' : '' }}" id="error-logs-tab" data-bs-toggle="tab" data-bs-target="#error-logs" type="button" role="tab" aria-controls="error-logs" aria-selected="{{ $activeTab === 'error' ? 'true' : 'false' }}" data-tab-key="error">Error Logs</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link {{ $activeTab === 'commands' ? 'active' : '' }}" id="commands-tab" data-bs-toggle="tab" data-bs-target="#commands" type="button" role="tab" aria-controls="commands" aria-selected="{{ $activeTab === 'commands' ? 'true' : 'false' }}" data-tab-key="commands">Device Commands</button>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade {{ $activeTab === 'raw' ? 'show active' : '' }}" id="raw-logs" role="tabpanel" aria-labelledby="raw-logs-tab">
            <div class="card table-shell">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">Raw Device Requests</h5>
                        <div class="shell-subtitle">Payloads received from devices before they are normalized into attendance, biometric, or sync records. Showing {{ $rawLogs->firstItem() ?? 0 }}-{{ $rawLogs->lastItem() ?? 0 }} of {{ $rawLogs->total() }}.</div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
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
                                        <td><code>{{ $log->endpoint }}</code></td>
                                        <td>
                                            <span class="badge bg-{{ $log->processing_status === 'processed' ? 'success' : ($log->processing_status === 'failed' ? 'danger' : 'warning') }}">
                                                {{ ucfirst($log->processing_status) }}
                                            </span>
                                        </td>
                                        <td>{{ $log->record_count }}</td>
                                        <td>{{ $log->created_at->diffForHumans() }}</td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="viewRawLog({{ $log->id }})">
                                                <i class="bi bi-eye me-1"></i>
                                                Inspect
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6">
                                            <div class="empty-state">
                                                <i class="bi bi-database-slash"></i>
                                                <div class="fw-semibold">No raw logs found</div>
                                                <div class="small">This section populates when devices post ADMS payloads to the server.</div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                @if($rawLogs->hasPages())
                    <div class="card-footer">
                        {{ $rawLogs->appends(request()->except('raw_page') + ['tab' => 'raw'])->links('pagination::bootstrap-5') }}
                    </div>
                @endif
            </div>
        </div>

        <div class="tab-pane fade {{ $activeTab === 'error' ? 'show active' : '' }}" id="error-logs" role="tabpanel" aria-labelledby="error-logs-tab">
            <div class="card table-shell">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">Error Diagnostics</h5>
                        <div class="shell-subtitle">Exceptions and warnings captured during device ingest, parsing, and command processing. Showing {{ $errorLogs->firstItem() ?? 0 }}-{{ $errorLogs->lastItem() ?? 0 }} of {{ $errorLogs->total() }}.</div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
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
                                        <td><span class="badge bg-{{ $error->severity }}">{{ ucfirst($error->severity) }}</span></td>
                                        <td>{{ \Illuminate\Support\Str::limit($error->message, 90) }}</td>
                                        <td>{{ $error->created_at->diffForHumans() }}</td>
                                        <td>
                                            <span class="badge bg-{{ $error->resolved ? 'success' : 'warning' }}">
                                                {{ $error->resolved ? 'Resolved' : 'Unresolved' }}
                                            </span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6">
                                            <div class="empty-state">
                                                <i class="bi bi-patch-check"></i>
                                                <div class="fw-semibold">No errors found</div>
                                                <div class="small">The diagnostics queue is currently clear.</div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                @if($errorLogs->hasPages())
                    <div class="card-footer">
                        {{ $errorLogs->appends(request()->except('error_page') + ['tab' => 'error'])->links('pagination::bootstrap-5') }}
                    </div>
                @endif
            </div>
        </div>

        <div class="tab-pane fade {{ $activeTab === 'commands' ? 'show active' : '' }}" id="commands" role="tabpanel" aria-labelledby="commands-tab">
            <div class="card table-shell">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">Recent Device Commands</h5>
                        <div class="shell-subtitle">Queue and completion history for device-side control operations. Showing {{ $commands->firstItem() ?? 0 }}-{{ $commands->lastItem() ?? 0 }} of {{ $commands->total() }}.</div>
                    </div>
                    <a href="{{ route('admin.commands.create') }}" class="btn btn-outline-primary btn-sm">Send Command</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
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
                                        <td colspan="5">
                                            <div class="empty-state">
                                                <i class="bi bi-terminal-x"></i>
                                                <div class="fw-semibold">No commands queued</div>
                                                <div class="small">Create a command to start building device-side operation history.</div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                @if($commands->hasPages())
                    <div class="card-footer">
                        {{ $commands->appends(request()->except('command_page') + ['tab' => 'commands'])->links('pagination::bootstrap-5') }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="modal fade" id="rawLogModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Raw Payload</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <pre id="rawPayloadContent" class="bg-dark text-light p-3 rounded mb-0" style="font-size: 0.875rem; min-height: 16rem;"></pre>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('extra_js')
    <script>
        const rawLogUrlTemplate = @json(route('admin.logs.raw', ['id' => '__LOG_ID__']));

        function viewRawLog(logId) {
            fetch(rawLogUrlTemplate.replace('__LOG_ID__', logId))
                .then((response) => response.text())
                .then((data) => {
                    document.getElementById('rawPayloadContent').textContent = data;
                    new bootstrap.Modal(document.getElementById('rawLogModal')).show();
                })
                .catch(() => {
                    document.getElementById('rawPayloadContent').textContent = 'Unable to load the raw payload for this request.';
                    new bootstrap.Modal(document.getElementById('rawLogModal')).show();
                });
        }

        document.querySelectorAll('#logsTabs [data-tab-key]').forEach((tabTrigger) => {
            tabTrigger.addEventListener('shown.bs.tab', (event) => {
                const tabKey = event.target.getAttribute('data-tab-key');
                const url = new URL(window.location.href);

                url.searchParams.set('tab', tabKey);
                window.history.replaceState({}, '', url);
            });
        });
    </script>
@endpush
