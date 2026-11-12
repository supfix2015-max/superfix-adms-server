@extends('admin.layout_biocloud')

@php
    $titles = [
        'employee' => 'Employee', 'department' => 'Department',
        'area' => 'Area', 'position' => 'Position',
        'holiday' => 'Holiday', 'timetable' => 'Timetable', 'shift' => 'Shift',
        'department-schedule' => 'Department Schedule',
        'employee-schedule' => 'Employee Schedule',
        'temporary-schedule' => 'Temporary Schedule',
        'employee-leave' => 'Employee Leave',
    ];
    $name = $titles[$section] ?? ucfirst($section);
@endphp

@section('title', 'BioCloud · '.$name)
@section('page_title', $name)
@section('page_subtitle', 'Original BioCloud data from Att2000_V4 SQL Server, read-only with existing reader permissions.')

@section('content')
<style>
.bc-directory-actions{display:flex;gap:5px;padding:13px 14px 8px;background:white;flex-wrap:wrap}
.bc-directory-actions .btn{font-size:12px;border-radius:3px}
.bc-directory-filters{display:flex;gap:8px;flex-wrap:wrap;background:#b9dea1;padding:8px 12px}
.bc-directory-filters input,.bc-directory-filters select{flex:1;min-width:155px;font-size:13px}
.bc-directory-grid{font-size:12px;min-width:680px}
.bc-directory-grid thead th{background:#2c3542;color:#fff;font-size:11px;padding:8px;white-space:nowrap;border-right:1px solid #5f6b77}
.bc-directory-grid tbody td{border-right:1px solid #eee}
.bc-directory-grid tbody tr:hover td{background:#ecf7e7}
</style>
<div class="card overflow-hidden p-0">
    <div class="bc-directory-actions">
        <span class="btn btn-outline-primary disabled" aria-disabled="true" title="Original SQL write permissions are intentionally disabled">
            <i class="bi bi-plus"></i> Add
        </span>
        <span class="btn btn-outline-success disabled" aria-disabled="true" title="Original SQL write permissions are intentionally disabled">
            <i class="bi bi-pencil"></i> Update
        </span>
        <span class="btn btn-outline-danger disabled" aria-disabled="true" title="No DELETE permission on original records">
            <i class="bi bi-trash"></i> Delete
        </span>
        <span class="small text-muted align-self-center ms-auto">
            <i class="bi bi-shield-lock"></i> Source read-only · No original records modified
        </span>
    </div>

    @if ($notice)
        <div class="alert alert-warning rounded-0 m-0">{{ $notice }}</div>
    @endif

    @if ($section === 'employee' && $rows)
        <form class="bc-directory-filters" action="{{ route('admin.directory.index', $section) }}" method="GET">
            <input class="form-control form-control-sm" placeholder="Search ID" name="id"
                   value="{{ $filters['id'] ?? '' }}">
            <input class="form-control form-control-sm" placeholder="Search Name" name="name"
                   value="{{ $filters['name'] ?? '' }}">
            <select name="department" class="form-select form-select-sm">
                <option value="">Search Department</option>
                @foreach ($departments as $id)
                    <option value="{{ $id }}" @selected((string) ($filters['department'] ?? '') === (string) $id)>
                        {{ $departmentNames[(string) $id] ?? 'Department '.$id }}
                    </option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-success btn-sm">Search</button>
            <a href="{{ route('admin.directory.index', $section) }}" class="btn btn-outline-dark btn-sm">Reset</a>
        </form>
    @endif

    @if ($rows)
        <div class="table-responsive">
            <table class="table table-striped table-hover table-sm mb-0 bc-directory-grid">
                <thead>
                    <tr>
                        @foreach ($columns as $column)
                            <th>{{ [
                                'BadgeNumber' => 'Badge Number', 'NAME' => 'Name',
                                'DepartmentId' => 'Department', 'CardNo' => 'Card No',
                                'HiredDay' => 'Hire Date',
                                'DepartmentName' => 'Department Name',
                                'AreaName' => 'Area Name',
                            ][$column] ?? $column }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $record)
                        <tr>
                            @foreach ($columns as $column)
                                <td>
                                    @if (strcasecmp($column, 'DepartmentId') === 0)
                                        {{ $departmentNames[(string) ($record->{$column} ?? '')]
                                            ?? ($record->{$column} ?? '—') }}
                                    @elseif (in_array(strtolower($column), ['hiredate', 'hiredday', 'startdate', 'enddate'], true))
                                        {{ $record->{$column}
                                            ? \Illuminate\Support\Carbon::parse($record->{$column})->format('Y-m-d')
                                            : '—' }}
                                    @else
                                        {{ $record->{$column} ?? '—' }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($columns) }}" class="text-center text-muted py-4">
                            No {{ strtolower($name) }} records match the current filter.
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="d-flex justify-content-between gap-3 flex-wrap p-3">
            <span class="small text-secondary">Total Records: <strong>{{ number_format($rows->total()) }}</strong> · {{ $rows->count() }} this page · Source dbo.{{ $table }}</span>
            {{ $rows->links('pagination::bootstrap-5') }}
        </div>
    @endif
</div>
@endsection
