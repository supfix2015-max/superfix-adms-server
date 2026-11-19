@extends('admin.layout')

@section('title', 'Fingerprint Review')
@section('page_title', 'Fingerprint Review & Synchronization')
@section('page_subtitle', 'Approve received fingerprint versions for selected ZKTeco devices before distribution.')

@section('content')
<div class="card shadow-sm p-4">
    @if (session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
    @if ($errors->any()) <div class="alert alert-danger">{{ $errors->first() }}</div> @endif
    @if ($duplicateCount > 0)
        <div class="alert alert-danger"><strong>{{ $duplicateCount }} duplicate employee ID warning(s).</strong> <a href="{{ route('admin.employees.device-registration',['state'=>'duplicate']) }}">Review employee registrations before distributing fingerprints.</a></div>
    @endif
    <div class="row g-3 mb-3">
        @foreach ([
            'Received templates' => $count,
            'Waiting for target poll' => (int)($counts['pending_user'] ?? 0) + (int)($counts['pending_fp'] ?? 0),
            'Awaiting device ACK' => (int)($counts['sent_user'] ?? 0) + (int)($counts['sent_fp'] ?? 0),
            'Device ACK / known present' => (int)($counts['done'] ?? 0) + (int)($counts['already_present'] ?? 0),
            'Needs investigation' => (int)($counts['failed'] ?? 0) + (int)($counts['unknown'] ?? 0) + (int)($counts['conflict'] ?? 0) + (int)($counts['stale'] ?? 0),
        ] as $label => $number)
            <div class="col-sm"><div class="border rounded p-3 h-100"><div class="small text-secondary">{{ $label }}</div><div class="fs-4 fw-bold">{{ number_format($number) }}</div></div></div>
        @endforeach
    </div>
    @unless ($ready)
        <div class="alert alert-warning">Review-only: the SQL administrator must run <code>docs/sql/adms-fingerprint-sync-reviews.sql</code> before approvals can be saved.</div>
    @endunless
    @if ($ready && !$enabled)
        <div class="alert alert-warning">Approvals can be saved; fingerprint delivery is disabled until <code>ZKTECO_FINGERPRINT_REVIEW_DISPATCH=true</code>. General command dispatch is independent.</div>
    @endif
    <div class="alert alert-info small">
        HTTP is supported. Delivery requires a verified device token or an explicitly configured, trusted source IP for that registered serial.
        Device ACK is not proof that the employee can authenticate. Known conflicting slots are held; a device may have local templates not known to the server.
        No raw fingerprint data is shown here.
    </div>
    <form method="GET" action="{{ route('admin.biometrics.review') }}" class="d-flex flex-wrap align-items-end gap-2 mb-3">
        <div class="flex-grow-1">
            <label for="fingerprint-search" class="form-label small">Search employee code, name or device</label>
            <input name="q" id="fingerprint-search" type="search" maxlength="80" value="{{ $search }}" class="form-control">
        </div>
        <button type="submit" class="btn btn-primary">Search</button>
        <a href="{{ route('admin.biometrics.review') }}" class="btn btn-outline-secondary">Clear</a>
        <a class="btn btn-outline-primary" href="{{ route('admin.biometrics.review', ['q'=>$search]) }}"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</a>
    </form>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
            <thead><tr><th>Employee</th><th>Source device</th><th>Finger slot</th><th>Received (Dubai)</th><th>Status / targets</th><th>Review</th></tr></thead>
            <tbody>
            @forelse ($rows as $row)
                @php
                    $history = $decisions->get($row->id, collect())->filter(fn ($d) => trim($d->version_hash) === $row->version_hash);
                    $rejected = $history->contains(fn ($d) => $d->state === 'rejected');
                    $valid = $row->has_template && $row->is_valid !== false && preg_match('/^[0-9]$/D', (string)$row->template_index) === 1;
                    $selectable = $targets->filter(fn ($t) => (int)$t->id !== (int)$row->device_id && !$history->contains(fn ($d) => (int)$d->target_device_id === (int)$t->id));
                @endphp
                <tr>
                    <td><strong>{{ $row->employee_code }}</strong><div>{{ $directoryNames->get($row->employee_code) ?: ($row->employee?->name ?: 'Verify against HR directory') }}</div></td>
                    <td>{{ $row->device?->name ?: 'Device '.$row->device_id }}<div class="small text-secondary">{{ $row->device?->serial_number }}</div></td>
                    <td>{{ $row->template_index ?? '—' }}</td>
                    <td class="text-nowrap">{{ $row->created_at?->copy()->timezone('Asia/Dubai')->format('Y-m-d H:i:s') ?? '—' }}<div class="small text-secondary">Updated: {{ $row->updated_at?->copy()->timezone('Asia/Dubai')->format('Y-m-d H:i:s') ?? '—' }}</div></td>
                    <td>
                        @if ($rejected)
                            <span class="badge bg-danger">Rejected</span>
                        @elseif ($history->isEmpty())
                            <span class="badge {{ $valid ? 'bg-warning text-dark' : 'bg-secondary' }}">{{ $valid ? 'Pending review' : 'Needs verification' }}</span>
                        @else
                            <span class="badge bg-info text-dark">Reviewed · {{ $history->count() }} target(s)</span>
                            <details class="mt-2">
                                <summary class="small">Target results</summary>
                                @foreach ($history as $decision)
                                    <div class="small mt-1"><strong>{{ $targets->firstWhere('id',$decision->target_device_id)?->name ?: $decision->target_serial }}</strong> — {{ str_replace('_',' ',$decision->state) }}
                                        @if ($decision->last_result)<div class="text-secondary">{{ str_replace('_',' ',$decision->last_result) }}</div>@endif
                                    </div>
                                @endforeach
                            </details>
                        @endif
                    </td>
                    <td style="min-width:220px">
                        @if ($ready && !$rejected && $row->version_hash)
                            @if ($valid && $selectable->isNotEmpty())
                                <details>
                                    <summary class="btn btn-sm btn-outline-primary">Choose devices</summary>
                                    <form class="mt-3" method="POST" action="{{ route('admin.biometrics.decide',$row->id) }}">
                                        @csrf
                                        <input type="hidden" name="version" value="{{ $row->version_hash }}">
                                        <input type="hidden" name="action" value="approve">
                                        <fieldset class="border rounded p-2 mb-2">
                                            <legend class="float-none w-auto small px-1">Registered target devices</legend>
                                            @foreach ($selectable as $target)
                                                <label class="d-block small"><input type="checkbox" name="devices[]" value="{{ $target->id }}"> {{ $target->name }} <span class="text-secondary">({{ $target->isOnline() ? 'online' : 'offline' }})</span></label>
                                            @endforeach
                                        </fieldset>
                                        <label class="d-block small mb-2"><input type="checkbox" name="create_user" value="1"> First create/update USERINFO (badge, name, normal-user privilege). This may update an existing user record.</label>
                                        <label class="d-block small mb-2"><input type="checkbox" name="confirm" value="1" required> I verified the employee, selected devices and fingerprint algorithm; an unknown local finger slot may be overwritten by firmware. I authorize this distribution.</label>
                                        <button type="submit" class="btn btn-sm btn-success">Approve &amp; Queue</button>
                                    </form>
                                </details>
                            @endif
                            @if ($history->isEmpty() && preg_match('/^[0-9]$/D', (string)$row->template_index) === 1)
                                <form method="POST" action="{{ route('admin.biometrics.decide',$row->id) }}" class="mt-2">
                                    @csrf
                                    <input type="hidden" name="version" value="{{ $row->version_hash }}">
                                    <button type="submit" name="action" value="reject" class="btn btn-sm btn-outline-danger">Reject version</button>
                                </form>
                            @endif
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-secondary py-4">No matching fingerprint records.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $rows->links('pagination::simple-bootstrap-5') }}
    <p class="small text-secondary mt-3 mb-0">Offline devices wait for their next authorized poll. Uncertain delivery is never automatically retried. Status totals are target decisions, including prior versions.</p>
</div>
@endsection
