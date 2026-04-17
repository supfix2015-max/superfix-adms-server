@extends('admin.layout')

@section('title', 'Device Commands')
@section('page_title', 'Device Commands')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Device Commands</h1>
    <a href="{{ route('admin.commands.create') }}" class="btn btn-success">
        <i class="bi bi-play-circle"></i> Send Command
    </a>
</div>

<div class="card">
    <div class="card-header">
        <h6 class="mb-0">Recent Commands ({{ $commands->total() }})</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Device</th>
                        <th>Command</th>
                        <th>Status</th>
                        <th>Parameters</th>
                        <th>Sent By</th>
                        <th>Sent</th>
                        <th>Executed</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($commands as $command)
                        <tr class="{{ $command->status === 'failed' ? 'table-danger' : ($command->status === 'pending' ? 'table-warning' : 'table-success') }}">
                            <td>
                                <a href="{{ route('admin.devices.show', $command->device) }}">
                                    {{ $command->device->name }}
                                </a>
                            </td>
                            <td>
                                <code class="fs-6">{{ $command->command }}</code>
                            </td>
                            <td>
                                <span class="badge bg-{{ $command->status === 'pending' ? 'warning' : ($command->status === 'done' ? 'success' : ($command->status === 'failed' ? 'danger' : 'secondary')) }}">
                                    {{ ucfirst($command->status) }}
                                </span>
                            </td>
                            <td>
                                @if($command->parameters)
                                    <small class="text-muted">
                                        {{ count($command->parameters) }} params
                                    </small>
                                @else
                                    <span class="text-muted small">-</span>
                                @endif
                            </td>
                            <td>
                                {{ $command->createdBy?->name ?? 'System' }}
                            </td>
                            <td>
                                <small>{{ $command->created_at->diffForHumans() }}</small>
                            </td>
                            <td>
                                {{ $command->executed_at?->diffForHumans() ?? '-' }}
                            </td>
                            <td>
                                <a href="{{ route('admin.commands.show', $command) }}" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center py-5">
                                <i class="bi bi-play-circle display-4 text-muted mb-3"></i>
                                <p class="text-muted mb-4">No commands sent yet</p>
                                <a href="{{ route('admin.commands.create') }}" class="btn btn-success">
                                    Send First Command
                                </a>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3 border-top bg-light">
            {{ $commands->links('pagination::bootstrap-5') }}
        </div>
    </div>
</div>

@endsection
