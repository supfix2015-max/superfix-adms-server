@extends('admin.layout')

@section('title', 'Command Details')
@section('page_title', 'Command #{{ $command->id }}')
@section('content')
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <h5 class="mb-0">
                    <i class="bi bi-{{ $command->status === 'pending' ? 'clock-history' : ($command->status === 'done' ? 'check-circle' : 'x-circle') }}"></i>
                    {{ ucfirst($command->command) }} 
                    <span class="badge bg-{{ $command->status === 'pending' ? 'warning' : ($command->status === 'done' ? 'success' : 'danger') }}">
                        {{ ucfirst($command->status) }}
                    </span>
                </h5>
                <a href="{{ route('admin.devices.show', $command->device) }}" class="btn btn-sm btn-outline-primary">
                    Device: {{ $command->device->name }}
                </a>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <strong>Device:</strong><br>
                        <a href="{{ route('admin.devices.show', $command->device) }}">{{ $command->device->name }}</a>
                        <br><small class="text-muted">{{ $command->device->serial_number }}</small>
                    </div>
                    <div class="col-md-6">
                        <strong>Sent:</strong><br>
                        <span class="text-muted">{{ $command->created_at->format('M d, Y H:i:s') }}</span><br>
                        <small>{{ $command->created_at->diffForHumans() }}</small>
                    </div>
                    <div class="col-md-6">
                        <strong>Executed:</strong><br>
                        @if($command->executed_at)
                            <span class="text-success">{{ $command->executed_at->format('M d, Y H:i:s') }}</span>
                        @else
                            <span class="text-muted">-</span>
                        @endif
                    </div>
                    <div class="col-md-6">
                        <strong>Sent By:</strong><br>
                        {{ $command->createdBy?->name ?? 'System' }}
                    </div>
                    @if($command->parameters && count($command->parameters))
                        <div class="col-12">
                            <strong>Parameters:</strong>
                            <div class="mt-2">
                                @foreach($command->parameters as $key => $value)
                                    <div class="bg-light p-2 rounded mb-1 small">
                                        <strong>{{ $key }}:</strong> {{ $value }}
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    @if($command->notes)
                        <div class="col-12">
                            <strong>Notes:</strong>
                            <div class="bg-light p-3 rounded mt-2">{{ $command->notes }}</div>
                        </div>
                    @endif
                    @if($command->error_message)
                        <div class="col-12">
                            <strong>Error:</strong>
                            <div class="alert alert-danger mt-2">
                                {{ $command->error_message }}
                            </div>
                        </div>
                    @endif
                </div>
            </div>
            <div class="card-footer bg-light">
                <div class="d-flex gap-2">
                    <a href="{{ route('admin.commands.index') }}" class="btn btn-secondary">← Back to Commands</a>
                    @if($command->status === 'pending' && Route::has('admin.commands.cancel'))
                        <form method="POST" action="{{ route('admin.commands.cancel', $command) }}" class="d-inline">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-warning" onclick="return confirm('Cancel this command?')">
                                Cancel Command
                            </button>
                        </form>
                    @endif
                    <div class="ms-auto">
                        <a href="{{ route('admin.commands.create', ['device_id' => $command->device_id]) }}" class="btn btn-success">
                            <i class="bi bi-plus"></i> Send Another
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h6>Device Status</h6>
            </div>
            <div class="card-body">
                <div class="text-center mb-3">
                    <div class="h4 mb-1">{{ $command->device->name }}</div>
                    <span class="badge bg-{{ $command->device->status === 'active' ? 'success' : 'danger' }}">
                        {{ ucfirst($command->device->status) }}
                    </span>
                </div>
                <dl class="row g-2 small">
                    <dt class="col-6">Serial:</dt>
                    <dd class="col-6">{{ $command->device->serial_number }}</dd>
                    <dt class="col-6">IP:</dt>
                    <dd class="col-6">{{ $command->device->ip_address }}:{{ $command->device->port }}</dd>
                    <dt class="col-6">Type:</dt>
                    <dd class="col-6">{{ ucfirst($command->device->device_type) }}</dd>
                    <dt class="col-6">Last Seen:</dt>
                    <dd class="col-6">{{ $command->device->last_heartbeat?->diffForHumans() ?? 'Never' }}</dd>
                </dl>
            </div>
        </div>
    </div>
</div>

@endsection
