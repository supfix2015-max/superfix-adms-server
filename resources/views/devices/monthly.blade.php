@extends('layouts.app')

@section('title', 'Monthly Attendance Summary')

@section('content')
    <div class="card mb-4">
        <div class="card-body">
            <div class="page-toolbar">
                <div>
                    <span class="eyebrow text-primary fw-bold text-uppercase small">Legacy Reports</span>
                    <h1 class="mt-2 mb-1">Monthly Attendance Summary</h1>
                    <p class="small mb-0">Review daily total hours for one employee across a selected date range.</p>
                </div>
                <button id="filter_button" class="btn btn-primary">
                    <i class="bi bi-bar-chart-line me-1"></i>
                    Show Records
                </button>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label for="start_date" class="form-label fw-semibold">Start Date</label>
                    <input type="date" id="start_date" class="form-control" placeholder="Start Date" required>
                </div>
                <div class="col-md-3">
                    <label for="end_date" class="form-label fw-semibold">End Date</label>
                    <input type="date" id="end_date" class="form-control" placeholder="End Date" required>
                </div>
                <div class="col-md-3">
                    <label for="employee_id" class="form-label fw-semibold">Employee ID</label>
                    <input type="text" id="employee_id" class="form-control" placeholder="Employee ID">
                </div>
                <div class="col-md-3">
                    <label for="employee_name" class="form-label fw-semibold">Employee Name</label>
                    <input type="text" id="employee_name" class="form-control" placeholder="Employee Name">
                </div>
            </div>
        </div>
    </div>

    <div class="card table-shell">
        <div class="card-header">
            <div>
                <h5 class="shell-title">Monthly Summary Table</h5>
                <div class="shell-subtitle">Daily total-hour rollup for the selected employee and date range.</div>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="attendanceTable">
                    <thead>
                        <tr>
                            <th>Employee ID</th>
                            <th>Employee Name</th>
                            <th>Date</th>
                            <th>Total Hours</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
<script>
    $(document).ready(function () {
        const table = $('#attendanceTable').DataTable({
            processing: true,
            serverSide: false,
            ajax: {
                url: "{{ route('devices.getMonthlyAttendanceSummary') }}",
                data: function (d) {
                    d.start_date = $('#start_date').val();
                    d.end_date = $('#end_date').val();
                    d.employee_id = $('#employee_id').val();
                    d.employee_name = $('#employee_name').val();
                }
            },
            dom: '<"row mb-3"<"col-md-6"l><"col-md-6 text-md-end"f>>t<"row mt-3"<"col-md-6"B><"col-md-6"p>>',
            buttons: ['excel', 'pdf', 'csv'],
            columns: [
                { data: 'employee_id', name: 'employee_id' },
                { data: 'employee_name', name: 'employee_name' },
                { data: 'date', name: 'date' },
                { data: 'total_hours', name: 'total_hours' },
            ],
            order: [[2, 'asc']]
        });

        $('#filter_button').click(function () {
            if (!$('#start_date').val() || !$('#end_date').val() || (!$('#employee_id').val() && !$('#employee_name').val())) {
                alert('Please enter start date, end date, and either employee ID or name.');
                return;
            }
            table.ajax.reload();
        });

        const today = new Date();
        const firstDayOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
        $('#start_date').val(firstDayOfMonth.toISOString().split('T')[0]);
        $('#end_date').val(today.toISOString().split('T')[0]);
    });
</script>
@endsection
