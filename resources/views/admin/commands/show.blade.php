@extends('admin.layout')

@section('title', 'Command Details')
@section('page_title', 'Command Detail')
@section('page_subtitle', 'Inspect payload, timing, operator context, and device execution state for a specific queued command.')

@section('page_actions')
    <a href="{{ route('admin.commands.create', ['device_id' => $command->device_id]) }}" class="btn btn-success">
        <i class="bi bi-plus-lg me-1"></i>
        Send Another
    </a>
@endsection

@section('content')
    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 w-100">
                        <div>
                            <h5 class="shell-title mb-1">
                                {{ ucfirst($command->command) }}
                            </h5>
                            <div class="shell-subtitle">Command #{{ $command->id }} routed to {{ $command->device->name }}.</div>
                        </div>
                        <span class="badge bg-{{ $command->status === 'pending' ? 'warning' : ($command->status === 'done' ? 'success' : 'danger') }}">
                            {{ ucfirst($command->status) }}
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="stat-pill">
                                <span>Device</span>
                                <strong><a href="{{ route('admin.devices.show', $command->device) }}">{{ $command->device->name }}</a></strong>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="stat-pill">
                                <span>Queued</span>
                                <strong>{{ $command->created_at->format('M d, Y H:i:s') }}</strong>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="stat-pill">
                                <span>Executed</span>
                                <strong>{{ $command->executed_at?->format('M d, Y H:i:s') ?? '-' }}</strong>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="stat-pill">
                                <span>Sent By</span>
                                <strong>{{ $command->createdBy?->name ?? 'System' }}</strong>
                            </div>
                        </div>
                    </div>

                    @if($command->parameters && count($command->parameters))
                        <div class="mt-4">
                            <h6 class="mb-3">Parameters</h6>
                            <div class="row g-2">
                                @foreach($command->parameters as $key => $value)
                                    <div class="col-md-6">
                                        <div class="stat-pill">
                                            <span>{{ $key }}</span>
                                            <strong>{{ is_array($value) ? json_encode($value) : $value }}</strong>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if($command->notes)
                        <div class="mt-4">
                            <h6 class="mb-2">Notes</h6>
                            <div class="stat-pill">{{ $command->notes }}</div>
                        </div>
                    @endif

                    @if($command->error_message)
                        <div class="alert alert-danger mt-4 mb-0">
                            <strong class="d-block mb-1">Execution Error</strong>
                            {{ $command->error_message }}
                        </div>
                    @endif
                </div>
                <div class="card-footer">
                    <div class="toolbar-cluster">
                        <a href="{{ route('admin.commands.index') }}" class="btn btn-outline-secondary">Back To Queue</a>
                        @if($command->status === 'pending' && Route::has('admin.commands.cancel'))
                            <form method="POST" action="{{ route('admin.commands.cancel', $command) }}" class="d-inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-outline-warning" onclick="return confirm('Cancel this command?')">
                                    Cancel Command
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">Device Context</h5>
                        <div class="shell-subtitle">Operational state of the target terminal.</div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="stat-stack">
                        <div class="stat-pill">
                            <span>Status</span>
                            <strong>{{ ucfirst($command->device->status) }}</strong>
                        </div>
                        <div class="stat-pill">
                            <span>Serial</span>
                            <strong>{{ $command->device->serial_number }}</strong>
                        </div>
                        <div class="stat-pill">
                            <span>Endpoint</span>
                            <strong>{{ $command->device->ip_address }}:{{ $command->device->port }}</strong>
                        </div>
                        <div class="stat-pill">
                            <span>Last Seen</span>
                            <strong>{{ $command->device->last_heartbeat?->diffForHumans() ?? 'Never' }}</strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
