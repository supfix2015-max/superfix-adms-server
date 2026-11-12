@extends('admin.layout')

@section('title', 'Attendance Transactions')
@section('page_title', 'Attendance · Transactions')
@section('page_subtitle', 'Live read-only view of Att2000_V4.dbo.Attendance. Matched to original employees and devices by their exact IDs.')

@section('content')
<style>
    .bio-attendance-shell { border: 1px solid #dce5df; border-radius: 10px; overflow: hidden; background: white; }
    .bio-attendance-filter { display: flex; align-items: end; gap: 9px; flex-wrap: wrap; padding: 13px; background: #f3f7fc; border-bottom: 1px solid #dce5f0; }
    .bio-attendance-filter .field { flex: 1 1 140px; min-width: 130px; }
    .bio-attendance-filter .field.name-field { flex-basis: 190px; }
    .bio-attendance-filter .field.date-field { flex-basis: 155px; }
    .bio-attendance-filter label { display: block; margin-bottom: 4px; color: #37465c; font-size: 11px; font-weight: 700; }
    .bio-attendance-filter .form-control, .bio-attendance-filter .form-select { height: 35px; border-radius: 4px; font-size: 12px; }
    .bio-attendance-filter .action { display: flex; gap: 6px; padding-bottom: 1px; }
    .bio-attendance-filter .btn { white-space: nowrap; }
    .bio-attendance-table { margin: 0; font-size: 12px; min-width: 1180px; }
    .bio-attendance-table thead th { position: sticky; top: 0; z-index: 1; background: #e9f0f9; color: #334155; font-size: 11px; font-weight: 700; vertical-align: middle; white-space: nowrap; border-right: 1px solid #d5deeb; }
    .bio-attendance-table tbody td { vertical-align: middle; border-right: 1px solid #e6e9e6; }
    .bio-attendance-table tbody tr:hover td { background: #f1f6fe; }
    .bio-attendance-table .serial { color: #667381; font-family: monospace; }
    .bio-attendance-table .punch { white-space: nowrap; font-variant-numeric: tabular-nums; font-weight: 600; }
    .bio-attendance-table .status-in { color: #11664c; }
    .bio-attendance-table .status-out { color: #90571d; }
    .bio-attendance-meta { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; padding: 10px 14px; color: #506072; font-size: 12px; }
    .bio-attendance-meta .pagination { margin: 0; }
    @media(max-width: 700px) {
        .bio-attendance-filter .field { flex-basis: calc(50% - 8px); min-width: 130px; }
        .bio-attendance-filter .action { width: 100%; }
    }
</style>

<div class="bio-attendance-shell">
    <form method="GET" action="{{ route('admin.legacy.index') }}" class="bio-attendance-filter" aria-label="Attendance search">
        <input type="hidden" name="tab" value="attendance">
        <div class="field">
            <label for="punch-badge">Employee ID</label>
            <input class="form-control" id="punch-badge" name="badge" type="search" maxlength="80"
                   value="{{ $filters['badge'] ?? '' }}" placeholder="Search ID">
        </div>
        <div class="field name-field">
            <label for="punch-name">Employee name</label>
            <input class="form-control" id="punch-name" name="name" type="search" maxlength="80"
                   value="{{ $filters['name'] ?? '' }}" placeholder="Search Name">
        </div>
        <div class="field">
            <label for="punch-department">Department</label>
            <select class="form-select" id="punch-department" name="department">
                <option value="">All departments</option>
                @foreach ($departmentIds as $id)
                    <option value="{{ $id }}" @selected((string) ($filters['department'] ?? '') === (string) $id)>
                        {{ $departments[(string) $id] ?? 'Department '.$id }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="punch-device">Device</label>
            <select class="form-select" id="punch-device" name="device">
                <option value="">All devices</option>
                @foreach ($devices as $device)
                    <option value="{{ $device->DeviceSerialNumber }}"
                        @selected(($filters['device'] ?? '') === $device->DeviceSerialNumber)>
                        {{ $device->DeviceAliasName ?: ($device->DeviceName ?: $device->DeviceSerialNumber) }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="field date-field">
            <label for="punch-from">From date</label>
            <input class="form-control" id="punch-from" type="date" name="date_from"
                   value="{{ $filters['date_from'] }}">
        </div>
        <div class="field date-field">
            <label for="punch-to">To date</label>
            <input class="form-control" id="punch-to" type="date" name="date_to"
                   value="{{ $filters['date_to'] }}">
        </div>
        <div class="field" style="flex:0 1 95px;min-width:90px">
            <label for="punch-perpage">Rows</label>
            <select name="per_page" id="punch-perpage" class="form-select">
                @foreach ([20, 50, 100] as $size)
                    <option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 20) === $size)>{{ $size }}</option>
                @endforeach
            </select>
        </div>
        <div class="action">
            <button class="btn btn-success btn-sm" type="submit">
                <i class="bi bi-search me-1"></i> Search
            </button>
            <a class="btn btn-outline-dark btn-sm"
               href="{{ route('admin.legacy.index', ['tab' => 'attendance']) }}">Reset</a>
            <a class="btn btn-success btn-sm"
               href="{{ route('admin.legacy.attendance.export', array_filter(
                 [
                   'badge' => $filters['badge'] ?? null,
                   'name' => $filters['name'] ?? null,
                   'department' => $filters['department'] ?? null,
                   'device' => $filters['device'] ?? null,
                   'date_from' => $filters['date_from'],
                   'date_to' => $filters['date_to'],
                   'search' => $search,
                 ], fn ($v) => $v !== null && $v !== ''
               )) }}"
               title="Download matching transactions as CSV (up to 31 days / 50,000 records)">
                <i class="bi bi-download me-1"></i> Export
            </a>
        </div>
    </form>

    <div class="table-responsive" style="max-height: 71vh;">
        <table class="table table-striped table-hover bio-attendance-table">
            <thead>
                <tr>
                    <th>Employee ID</th>
                    <th>Name</th>
                    <th>Department Name</th>
                    <th>Device Name</th>
                    <th>Punch Date</th>
                    <th>Status</th>
                    <th>Verify Type</th>
                    <th>Device Serial</th>
                    <th>Remark</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td><strong>{{ $row->BadgeNumber ?: '—' }}</strong></td>
                        <td>{{ $row->EmployeeName ?: 'Unknown employee' }}</td>
                        <td>{{ $departments[(string) ($row->DepartmentId ?? '')] ?? ($row->DepartmentId !== null ? 'Department '.$row->DepartmentId : '—') }}</td>
                        <td>{{ $row->DeviceAliasName ?: ($row->DeviceModelName ?: 'Unknown device') }}</td>
                        <td class="punch">{{ $row->VerifyTime ? \Illuminate\Support\Carbon::parse($row->VerifyTime)->format('Y-m-d H:i:s') : '—' }}</td>
                        <td class="{{ stripos((string) $row->Status, 'out') !== false ? 'status-out' : 'status-in' }}">
                            {{ $row->Status ?: '—' }}
                        </td>
                        <td>{{ $row->VerifyType ?: '—' }}</td>
                        <td class="serial">{{ $row->DeviceSerialNumber ?: '—' }}</td>
                        <td>{{ $row->Remark ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center py-5 text-muted">
                        No attendance records match these filters.
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="bio-attendance-meta">
        <div>
            <i class="bi bi-database-check me-1"></i>
            Source: <strong>Att2000_V4.dbo.Attendance</strong> · Total Records: <strong>{{ number_format($rows->total()) }}</strong> ·
            {{ $rows->count() }} this page · Latest first
        </div>
        <div>{{ $rows->links('pagination::bootstrap-5') }}</div>
    </div>
</div>
@endsection
