@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Monthly Attendance</h1>

    <div class="row mb-3">
        <div class="col-md-3">
            <input type="date" id="start_date" class="form-control" placeholder="Start Date" required>
        </div>
        <div class="col-md-3">
            <input type="date" id="end_date" class="form-control" placeholder="End Date" required>
        </div>
        <div class="col-md-3">
            <input type="text" id="employee_id" class="form-control" placeholder="Employee ID">
        </div>
        <div class="col-md-3">
            <input type="text" id="employee_name" class="form-control" placeholder="Employee Name">
        </div>
        <div class="col-md-6 mt-3">
            <button id="filter_button" class="btn btn-primary w-25">Show Records</button>
        </div>
    </div>

    <table class="table table-bordered" id="attendanceTable">
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
@endsection

@section('scripts') 
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
    $(document).ready(function () {
        var table = $('#attendanceTable').DataTable({
            processing: true,
            serverSide: false, // Changed to false as we're not using server-side processing
            ajax: {
                url: "{{ route('devices.getMonthlyAttendanceSummary') }}",
                data: function (d) {
                    d.start_date = $('#start_date').val();
                    d.end_date = $('#end_date').val();
                    d.employee_id = $('#employee_id').val();
                    d.employee_name = $('#employee_name').val();
                }
            },
            dom: '<"row"<"col-md-6"l><"col-md-6"f>>rtip<"row"<"col-md-6"B>>',
            buttons: [
                'excel', 'pdf', 'csv'
            ],
            columns: [
                { data: 'employee_id', name: 'employee_id' },
                { data: 'employee_name', name: 'employee_name' },
                { data: 'date', name: 'date' },
                { data: 'total_hours', name: 'total_hours' },
            ],
            order: [[2, 'asc']] // Order by date
        });

        $('#filter_button').click(function () {
            if (!$('#start_date').val() || !$('#end_date').val() || (!$('#employee_id').val() && !$('#employee_name').val())) {
                alert('Please enter start date, end date, and either employee ID or name.');
                return;
            }
            table.ajax.reload();
        });

        // Set default dates
        var today = new Date();
        var firstDayOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
        $('#start_date').val(firstDayOfMonth.toISOString().split('T')[0]);
        $('#end_date').val(today.toISOString().split('T')[0]);
    });
</script>
@endsection