@extends('admin.layout')
@section('title','Device Employee List')
@section('page_title','Device Employee List')
@section('page_subtitle','Read the selected terminal roster without importing employees')
@section('content')
<div class="card shadow-sm p-4">
    @if(session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
    @if($errors->any()) <div class="alert alert-danger">{{ $errors->first() }}</div> @endif
    <form method="GET" action="{{ route('admin.devices.roster-preview') }}" class="d-flex flex-wrap align-items-end gap-2 mb-4">
        <div class="flex-grow-1">
            <label class="form-label" for="device_id">Select terminal</label>
            <select class="form-select" id="device_id" name="device_id" required>
                <option value="">Choose a device</option>
                @foreach($devices as $device)
                    <option value="{{ $device->id }}" @selected($selected && (int)$selected->id===(int)$device->id)>{{ $device->name }} — {{ $device->isOnline()?'Online':'Offline' }} — {{ $device->serial_number }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn btn-primary">View device</button>
    </form>
    @if($selected)
        <h5 class="mb-3">{{ $selected->name }}</h5>
        <div class="row g-3 mb-3">
            <div class="col-sm"><div class="border rounded p-3"><div class="small text-secondary">Connection</div><strong>{{ $selected->isOnline()?'Online':'Offline' }}</strong></div></div>
            <div class="col-sm"><div class="border rounded p-3"><div class="small text-secondary">Query status</div><strong>{{ !$state?'Not requested':($state['status']==='closed'?'Closed':($state['ack_at']?'ACK: '.$state['return_code']:ucfirst($state['status']))) }}</strong></div></div>
            <div class="col-sm"><div class="border rounded p-3"><div class="small text-secondary">Unique employee IDs received</div><strong>{{ count(array_unique(array_column($rows,'pin'))) }}</strong></div></div>
        </div>
        @if(!$state || $state['status']==='closed')
            <form method="POST" action="{{ route('admin.devices.roster-preview.start') }}" class="mb-3">
                @csrf
                <input type="hidden" name="device_id" value="{{ $selected->id }}">
                <label class="d-block small mb-2"><input type="checkbox" name="confirm" value="1" required> No staff enrollment is in progress on this terminal. I will end the preview when all batches have arrived.</label>
                <button type="submit" class="btn btn-success">Query Employees</button>
            </form>
        @else
            <div class="alert alert-warning small">During this preview, the selected terminal's user-list uploads are isolated from employee import. User enrollment changes sent in the same window may also be isolated. Attendance uploads continue. Do not restart or redeploy until the preview is closed. Wait for all batches; ACK alone does not prove the roster is complete.</div>
            <div class="d-flex flex-wrap gap-3 align-items-start mb-3">
                <a class="btn btn-primary" href="{{ route('admin.devices.roster-preview',['device_id'=>$selected->id]) }}">Refresh results</a>
                <form method="POST" action="{{ route('admin.devices.roster-preview.close') }}">
                    @csrf
                    <input type="hidden" name="device_id" value="{{ $selected->id }}">
                    <label class="d-block small mb-2"><input type="checkbox" name="confirm" value="1" required> No further query replies are expected.</label>
                    <button class="btn btn-outline-secondary">End preview &amp; resume import</button>
                </form>
            </div>
        @endif
        <p class="small text-secondary">Read-only query. Names and PINs are held in temporary server file cache for 30 minutes after the last batch, not written to employee or raw-log tables. The capture window ends on explicit close or after two hours. A late reply outside this window may follow the normal upload path. Only authorized devices receive queries. Receipt of an employee name confirms a user record, not a fingerprint template or successful biometric authentication.</p>
        @if($state)
            <p class="small text-secondary">Received batches: {{ $state['batches'] }} · Last batch: {{ $state['last_batch_at'] ? \Carbon\CarbonImmutable::createFromTimestamp($state['last_batch_at'],'Asia/Dubai')->format('Y-m-d H:i:s') : '—' }} · Max preview rows: 10,000</p>
        @endif
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
                <thead><tr><th>#</th><th>Employee ID</th><th>Employee name on terminal</th></tr></thead>
                <tbody>
                @forelse($rows as $i=>$row)
                    <tr><td>{{ $i+1 }}</td><td><strong>{{ $row['pin'] }}</strong></td><td>{{ $row['name']!==''?$row['name']:'(No name)' }}</td></tr>
                @empty
                    <tr><td colspan="3" class="text-center text-secondary py-4">No employee records received yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
