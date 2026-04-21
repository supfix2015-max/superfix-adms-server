@extends('admin.layout')

@section('title', 'Device Commands')
@section('page_title', 'Command Queue')
@section('page_subtitle', 'Track queued, dispatched, completed, and failed device commands across the ADMS control plane.')

@section('page_actions')
    <a href="{{ route('admin.commands.create') }}" class="btn btn-success">
        <i class="bi bi-play-circle me-1"></i>
        Send Command
    </a>
@endsection

@section('content')
    <div class="card table-shell">
        <div class="card-header">
            <div>
                <h5 class="shell-title">Recent Commands</h5>
                <div class="shell-subtitle">{{ $commands->total() }} command records are available in the operational queue history.</div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Device</th>
                            <th>Command</th>
                            <th>Status</th>
                            <th>Parameters</th>
                            <th>Sent By</th>
                            <th>Queued</th>
                            <th>Executed</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($commands as $command)
                            <tr class="{{ $command->status === 'failed' ? 'table-danger' : ($command->status === 'pending' ? 'table-warning' : ($command->status === 'done' ? 'table-success' : '')) }}">
                                <td>
                                    <a href="{{ route('admin.devices.show', $command->device) }}" class="fw-semibold">
                                        {{ $command->device->name }}
                                    </a>
                                </td>
                                <td><code>{{ $command->command }}</code></td>
                                <td>
                                    <span class="badge bg-{{ $command->status === 'pending' ? 'warning' : ($command->status === 'done' ? 'success' : ($command->status === 'failed' ? 'danger' : 'secondary')) }}">
                                        {{ ucfirst($command->status) }}
                                    </span>
                                </td>
                                <td>
                                    @if($command->parameters)
                                        <span class="small text-muted">{{ count($command->parameters) }} parameters</span>
                                    @else
                                        <span class="small text-muted">No parameters</span>
                                    @endif
                                </td>
                                <td>{{ $command->createdBy?->name ?? 'System' }}</td>
                                <td>{{ $command->created_at->diffForHumans() }}</td>
                                <td>{{ $command->executed_at?->diffForHumans() ?? '-' }}</td>
                                <td>
                                    <a href="{{ route('admin.commands.show', $command) }}" class="btn btn-sm btn-outline-primary">
                                        Inspect
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8">
                                    <div class="empty-state">
                                        <i class="bi bi-terminal"></i>
                                        <div class="fw-semibold">No commands sent yet</div>
                                        <div class="small mb-3">Queue a device command to start tracking operational execution here.</div>
                                        <a href="{{ route('admin.commands.create') }}" class="btn btn-success">Send First Command</a>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer">
            {{ $commands->links('pagination::bootstrap-5') }}
        </div>
    </div>
@endsection
