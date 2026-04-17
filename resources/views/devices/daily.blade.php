@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Attendance</h1>

    <div class="row mb-3">
        <div class="col-md-3">
            <input type="date" id="date" class="form-control" placeholder="Date">
        </div>
        <div class="col-md-6">
            <button id="filter_button" class="btn btn-primary w-25">Show Records</button>
        </div>
    </div>

    <table class="table table-bordered" id="attendanceTable">
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
@endsection

@section('scripts') 
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const yesterday = new Date(new Date().setDate(new Date().getDate() - 1)).toISOString().split('T')[0];
        document.getElementById('date').max = yesterday;
    });

    $(document).ready(function () {
        var table = $('#attendanceTable').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: "{{ route('devices.getDailyAttendanceSummary') }}",
                data: function (d) {
                    d.start_date = $('#date').val();
                }
            },
            dom: '<"row"<"col-md-6"f>>t<"row"<"col-md-6"B>>',
            buttons: [
                'excel', 'pdf', 'pageLength'
            ],
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