@extends('admin.layout')
@section('title','Employee Registration Requests')
@section('page_title','Employee Registration Requests')
@section('page_subtitle','Device registrations and duplicate badge warnings')
@section('content')
<div class="card shadow-sm p-4">
    @if (session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
    @if ($errors->any()) <div class="alert alert-danger">{{ $errors->first() }}</div> @endif
    @unless ($ready)
        <div class="alert alert-warning">Registration storage is not yet provisioned by the SQL administrator.</div>
    @else
        @if (($counts['duplicate'] ?? 0)>0)
            <div class="alert alert-danger"><strong>{{ $counts['duplicate'] }} duplicate ID warning(s).</strong> Compare the device name with the employee already in the original directory. No existing employee was overwritten.</div>
        @endif
        <div class="row g-2 mb-3">
            @foreach (['pending'=>'Pending HR review','duplicate'=>'Duplicate ID','registered'=>'New employee registered','existing'=>'Existing employee / matching name'] as $key=>$label)
                <div class="col-sm"><a class="text-decoration-none text-reset" href="{{ route('admin.employees.device-registration',['state'=>$key]) }}"><div class="border rounded p-3"><div class="small text-secondary">{{ $label }}</div><div class="fs-4 fw-bold">{{ $counts[$key] ?? 0 }}</div></div></a></div>
            @endforeach
        </div>
        <p class="small text-secondary">Unverified HTTP sources require HR approval for new employees. A repeated badge with the same name is not proof of identity. Different names are held; correct a conflicting ID on the source device and enroll under the verified new ID. Do not distribute suspect templates.</p>
        <form class="d-flex gap-2 mb-3" method="GET">
            <select name="state" class="form-select w-auto">
                @foreach (['all','pending','duplicate','registered','existing','rejected'] as $option)
                    <option value="{{ $option }}" @selected($state===$option)>{{ ucfirst($option) }}</option>
                @endforeach
            </select>
            <button class="btn btn-outline-primary">Filter</button>
        </form>
        <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr><th>Employee ID</th><th>Name on device</th><th>Existing directory name (at review)</th><th>Source</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td><strong>{{ $row->employee_code }}</strong></td>
                    <td>{{ $row->incoming_name }}</td>
                    <td>{{ $row->existing_name ?? '—' }}</td>
                    <td>{{ $devices->get($row->device_id)?->name ?? 'Device '.$row->device_id }}<div class="small text-secondary">{{ $devices->get($row->device_id)?->serial_number }}</div></td>
                    <td><span class="badge {{ $row->state==='duplicate'?'bg-danger':($row->state==='pending'?'bg-warning text-dark':($row->state==='registered'?'bg-success':'bg-secondary')) }}">{{ $row->state==='duplicate'?'Duplicate ID':ucfirst($row->state) }}</span><div class="small text-secondary">{{ $row->created_at }} UTC</div></td>
                    <td>
                        @if ($row->state==='pending')
                            <form method="POST" action="{{ route('admin.employees.device-registration.approve',$row->id) }}">
                                @csrf
                                <label class="d-block small"><input type="checkbox" name="confirm" value="1" required> I verified the employee identity and badge.</label>
                                <button class="btn btn-sm btn-success mt-1">Approve registration</button>
                            </form>
                        @elseif ($row->state==='duplicate')
                            <span class="small text-danger">Correct ID on device; fingerprint distribution held.</span>
                        @else
                            <span class="small text-secondary">No directory overwrite</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-secondary py-3">No registration requests.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
        {{ $rows->links('pagination::simple-bootstrap-5') }}
    @endunless
</div>
@endsection
