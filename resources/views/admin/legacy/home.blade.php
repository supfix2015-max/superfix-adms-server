@extends('admin.layout_biocloud')
@section('title','BioCloud · Home')
@section('page_title','Superfix Attendance Workspace')
@section('content')
<div class="row g-3">
    <div class="col-md-6 col-xl-4">
        <a href="{{ route('admin.directory.index',['section'=>'employee']) }}" class="card p-4 text-decoration-none text-dark h-100">
            <i class="bi bi-people text-success fs-2"></i>
            <strong class="fs-5 my-2">Employee</strong>
            <div class="text-secondary">Original SQL Server employee records, departments, areas, and shift directories.</div>
        </a>
    </div>
    <div class="col-md-6 col-xl-4">
        <a href="{{ route('admin.fleet.index') }}" class="card p-4 text-decoration-none text-dark h-100">
            <i class="bi bi-hdd-network text-success fs-2"></i>
            <strong class="fs-5 my-2">Devices</strong>
            <div class="text-secondary">Original serial roster, recent ADMS activity, license-required checkbox, and protected command actions.</div>
        </a>
    </div>
    <div class="col-md-6 col-xl-4">
        <a href="{{ route('admin.legacy.index',['tab'=>'attendance']) }}" class="card p-4 text-decoration-none text-dark h-100">
            <i class="bi bi-calendar2-check text-success fs-2"></i>
            <strong class="fs-5 my-2">Attendance</strong>
            <div class="text-secondary">Original transaction records, employee names, filters and first/last, daily and monthly reports.</div>
        </a>
    </div>
</div>
<div class="alert alert-info mt-3">
    <strong>Source of truth:</strong> Att2000_V4.dbo for original employee, department, device and attendance data.
    Operational device metadata and license preferences are stored separately in the Att2000_V4.adms schema.
    Physical device commands are not executed merely by opening these pages.
</div>
@endsection
