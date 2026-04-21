@extends('layouts.app')

@section('title', 'Attendance Explorer')

@section('content')
    <div class="card mb-4">
        <div class="card-body">
            <div class="page-toolbar">
                <div>
                    <span class="eyebrow text-primary fw-bold text-uppercase small">Legacy Reports</span>
                    <h1 class="mt-2 mb-1">Attendance Explorer</h1>
                    <p class="small mb-0">Search raw attendance rows by date range, employee ID, and employee name.</p>
                </div>
                <button id="filter_button" class="btn btn-primary">
                    <i class="bi bi-funnel me-1"></i>
                    Apply Filters
                </button>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label for="start_date" class="form-label fw-semibold">Start Date</label>
                    <input type="date" id="start_date" class="form-control" placeholder="Start Date">
                </div>
                <div class="col-md-3">
                    <label for="end_date" class="form-label fw-semibold">End Date</label>
                    <input type="date" id="end_date" class="form-control" placeholder="End Date">
                </div>
                <div class="col-md-3">
                    <label for="employee_id_search" class="form-label fw-semibold">Employee ID</label>
                    <input type="text" id="employee_id_search" class="form-control" placeholder="Search Employee ID">
                </div>
                <div class="col-md-3">
                    <label for="employee_name_search" class="form-label fw-semibold">Employee Name</label>
                    <input type="text" id="employee_name_search" class="form-control" placeholder="Search Employee Name">
                </div>
            </div>
        </div>
    </div>

    <div class="card table-shell">
        <div class="card-header">
            <div>
                <h5 class="shell-title">Attendance Rows</h5>
                <div class="shell-subtitle">Direct view of the legacy attendance table with export controls.</div>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="attendanceTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>SN</th>
                            <th>Employee ID</th>
                            <th>Employee Name</th>
                            <th>Timestamp</th>
                            <th>Status</th>
                            <th>Type</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const today = new Date().toISOString().split('T')[0];
        const yesterday = new Date(new Date().setDate(new Date().getDate() - 1)).toISOString().split('T')[0];

        document.getElementById('start_date').value = yesterday;
        document.getElementById('end_date').value = today;
    });

    $(document).ready(function () {
        const table = $('#attendanceTable').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: "{{ route('devices.getAttendance') }}",
                data: function (d) {
                    d.start_date = $('#start_date').val();
                    d.end_date = $('#end_date').val();
                    d.employee_id = $('#employee_id_search').val();
                    d.employee_name = $('#employee_name_search').val();
                }
            },
            dom: '<"row mb-3"<"col-md-6"i><"col-md-6 text-md-end"f>>t<"row mt-3"<"col-md-6"B><"col-md-6"p>>',
            buttons: ['excel', 'pdf', 'pageLength'],
            searching: false,
            pageLength: 100,
            lengthMenu: [50, 100, 500, { label: 'All', value: -1 }],
            columns: [
                { data: 'id', name: 'id' },
                { data: 'sn', name: 'sn' },
                { data: 'employee_id', name: 'employee_id' },
                { data: 'employee_name', name: 'employee_name' },
                {
                    data: 'timestamp',
                    name: 'timestamp',
                    render: function (data) {
                        return new Date(data).toLocaleString('en-US', {
                            year: 'numeric',
                            month: 'numeric',
                            day: 'numeric',
                            hour: 'numeric',
                            minute: 'numeric',
                            hour12: true
                        });
                    }
                },
                {
                    data: 'status1',
                    name: 'status1',
                    render: function (data) {
                        return data == 1 ? 'Out' : 'In';
                    }
                },
                {
                    data: 'status2',
                    name: 'status2',
                    render: function (data) {
                        if (data == 15) return 'Face';
                        if (data == 25) return 'Palm';
                        return data;
                    }
                },
            ],
            order: [[0, 'desc']]
        });

        $('#filter_button').click(function () {
            table.draw();
        });

        $('#employee_id_search, #employee_name_search').on('keyup', function () {
            table.draw();
        });
    });
</script>
@endsection
