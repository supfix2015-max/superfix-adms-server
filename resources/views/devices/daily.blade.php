@extends('layouts.app')

@section('title', 'Daily Attendance Summary')

@section('content')
    <div class="card mb-4">
        <div class="card-body">
            <div class="page-toolbar">
                <div>
                    <span class="eyebrow text-primary fw-bold text-uppercase small">Legacy Reports</span>
                    <h1 class="mt-2 mb-1">Daily Attendance Summary</h1>
                    <p class="small mb-0">Aggregate first check-in, last checkout, and total time by employee for one selected day.</p>
                </div>
                <button id="filter_button" class="btn btn-primary">
                    <i class="bi bi-calendar2-check me-1"></i>
                    Show Records
                </button>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="date" class="form-label fw-semibold">Attendance Date</label>
                    <input type="date" id="date" class="form-control" placeholder="Date">
                </div>
            </div>
        </div>
    </div>

    <div class="card table-shell">
        <div class="card-header">
            <div>
                <h5 class="shell-title">Daily Summary Table</h5>
                <div class="shell-subtitle">Per-employee daily attendance summary built from the legacy attendance source.</div>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="attendanceTable">
                    <thead>
                        <tr>
                            <th>Employee ID</th>
                            <th>Employee Name</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>Total Time</th>
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
        const yesterday = new Date(new Date().setDate(new Date().getDate() - 1)).toISOString().split('T')[0];
        document.getElementById('date').max = yesterday;
        document.getElementById('date').value = yesterday;
    });

    $(document).ready(function () {
        const table = $('#attendanceTable').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: "{{ route('devices.getDailyAttendanceSummary') }}",
                data: function (d) {
                    d.start_date = $('#date').val();
                }
            },
            dom: '<"row mb-3"<"col-md-6"f><"col-md-6 text-md-end"B>>t<"row mt-3"<"col-md-6"i><"col-md-6"p>>',
            buttons: ['excel', 'pdf', 'pageLength'],
            searching: false,
            pageLength: 100,
            lengthMenu: [50, 100, 500, { label: 'All', value: -1 }],
            columns: [
                { data: 'employee_id', name: 'employee_id' },
                { data: 'employee_name', name: 'employee_name' },
                { data: 'first_in', name: 'first_in' },
                { data: 'last_out', name: 'last_out' },
                { data: 'total_time', name: 'total_time' },
            ],
            order: [[0, 'asc']]
        });

        $('#filter_button').click(function () {
            table.draw();
        });
    });
</script>
@endsection
