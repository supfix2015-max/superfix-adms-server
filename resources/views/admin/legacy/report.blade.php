@extends('admin.layout')

@section('title', 'ADMS · '.$reportName)
@section('page_title', $reportName)
@section('page_subtitle', 'Read-only attendance summaries from original Att2000_V4 SQL Server.')

@section('content')
<style>
.bc-report-filters{padding:12px;background:#f3f7fc;display:flex;gap:8px;flex-wrap:wrap}
.bc-report-filters .form-control,.bc-report-filters .form-select{min-width:150px;flex:1;font-size:12px}
.bc-report-table{font-size:12px;min-width:980px}
.bc-report-table thead th{background:#e9f0f9;color:#334155;white-space:nowrap;font-size:11px}
.bc-report-table td{white-space:nowrap}
</style>
<div class="card p-0 overflow-hidden">
    <form class="bc-report-filters" action="{{ route('admin.attendance-reports.show', ['report'=>$report]) }}" method="GET">
        <input class="form-control form-control-sm" type="search" name="badge" placeholder="Search employee ID"
               maxlength="80" value="{{ $filters['badge'] ?? '' }}">
        <select name="department" class="form-select form-select-sm">
            <option value="">All departments</option>
            @foreach ($departments as $department)
                <option value="{{ $department }}" @selected((string) ($filters['department'] ?? '') === (string) $department)>
                    {{ $departmentNames[(string) $department] ?? 'Department '.$department }}
                </option>
            @endforeach
        </select>
        <input class="form-control form-control-sm" type="date" name="date_from" value="{{ $filters['date_from'] }}">
        <input class="form-control form-control-sm" type="date" name="date_to" value="{{ $filters['date_to'] }}">
        <button class="btn btn-success btn-sm" type="submit">Search</button>
        <a class="btn btn-outline-dark btn-sm" href="{{ route('admin.attendance-reports.show', ['report'=>$report]) }}">Reset</a>
    </form>

    <div class="alert alert-info rounded-0 my-0 py-2 small">
        These values describe punch events, not payroll hours or verified shift compliance.
        Reports do not infer missing Check-In/Check-Out events.
    </div>

    <div class="table-responsive">
        <table class="table table-striped table-hover table-sm mb-0 bc-report-table">
            <thead>
                <tr>
                    <th>Employee ID</th>
                    <th>Name</th>
                    <th>Department Name</th>
                    <th>{{ $report === 'monthly' ? 'Month' : ($report === 'summary' ? 'Range' : 'Date') }}</th>
                    <th>{{ $report === 'first-in-last-out' ? 'First Check-In' : 'First Punch' }}</th>
                    <th>{{ $report === 'first-in-last-out' ? 'Last Check-Out' : 'Last Punch' }}</th>
                    <th>Check-Ins</th>
                    <th>Check-Outs</th>
                    <th>Total Transactions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    @php($emp = $employees->get($row->BadgeNumber))
                    <tr>
                        <td class="fw-semibold">{{ $row->BadgeNumber }}</td>
                        <td>{{ $emp?->NAME ?: 'Unknown employee' }}</td>
                        <td>{{ $departmentNames[(string) ($emp?->DepartmentId ?? '')] ?? ($emp?->DepartmentId ?? '—') }}</td>
                        <td>{{ $row->WorkPeriod }}</td>
                        @php($first = $report === 'first-in-last-out' ? $row->FirstIn : $row->FirstPunch)
                        @php($last = $report === 'first-in-last-out' ? $row->LastOut : $row->LastPunch)
                        <td>{{ $first ? \Illuminate\Support\Carbon::parse($first)->format('Y-m-d H:i:s') : '—' }}</td>
                        <td>{{ $last ? \Illuminate\Support\Carbon::parse($last)->format('Y-m-d H:i:s') : '—' }}</td>
                        <td>{{ $row->CheckIns }}</td>
                        <td>{{ $row->CheckOuts }}</td>
                        <td class="fw-semibold">{{ $row->TotalPunches }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">No records found for this date range.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="d-flex justify-content-between flex-wrap gap-3 p-3">
        <span class="text-secondary small">
            {{ $rows->count() }} grouped result rows this page · Source: dbo.Attendance · UAE local time
        </span>
        {{ $rows->links('pagination::simple-bootstrap-5') }}
    </div>
</div>
@endsection
