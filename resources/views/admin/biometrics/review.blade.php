@extends('admin.layout')

@section('title', 'Fingerprint Review')
@section('page_title', 'Fingerprint Review')
@section('page_subtitle', 'Review fingerprint templates received from ZKTeco devices before cross-device distribution.')

@section('content')
<div class="card shadow-sm p-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
        <div>
            <div class="text-secondary small">Received fingerprints · awaiting review</div>
            <div class="fs-3 fw-bold">{{ number_format($count) }}</div>
        </div>
        <a class="btn btn-outline-primary" href="{{ route('admin.biometrics.review', ['q' => $search]) }}"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</a>
    </div>
    <div class="alert alert-warning small">Review-only phase. This page lists received records, not a persisted approval history. No approval or device distribution is available here. Verify the employee identity and enrollment source before any future approval.</div>
    <form method="GET" action="{{ route('admin.biometrics.review') }}" class="d-flex flex-wrap align-items-end gap-2 mb-3">
        <div class="flex-grow-1">
            <label for="fingerprint-search" class="form-label small">Search employee code, name or device</label>
            <input name="q" id="fingerprint-search" type="search" maxlength="80" value="{{ $search }}" class="form-control">
        </div>
        <button type="submit" class="btn btn-primary">Search</button>
        <a href="{{ route('admin.biometrics.review') }}" class="btn btn-outline-secondary">Clear</a>
    </form>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
            <thead><tr><th>Employee code</th><th>Employee name</th><th>Source device</th><th>Finger slot</th><th>First received (Dubai)</th><th>Last updated (Dubai)</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row->employee_code }}</td>
                    <td>{{ $row->employee?->name ?: ($row->employee?->full_name ?: 'Unmatched') }}</td>
                    <td>{{ $row->device?->name ?: 'Device '.$row->device_id }}<div class="small text-secondary">{{ $row->device?->serial_number }}</div></td>
                    <td>{{ $row->template_index ?? '—' }}</td>
                    <td class="text-nowrap">{{ $row->created_at?->copy()->timezone('Asia/Dubai')->format('Y-m-d H:i:s') ?? '—' }}</td>
                    <td class="text-nowrap">{{ $row->updated_at?->copy()->timezone('Asia/Dubai')->format('Y-m-d H:i:s') ?? '—' }}</td>
                    <td>@if (!$row->has_template || !$row->is_valid || !$row->employee)<span class="badge bg-secondary">Needs verification</span>@else<span class="badge bg-warning text-dark">Pending review</span>@endif</td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-secondary py-4">No matching fingerprint records.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $rows->links('pagination::simple-bootstrap-5') }}
</div>
@endsection
