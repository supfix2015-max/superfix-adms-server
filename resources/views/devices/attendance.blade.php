@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Attendance</h1>

 <div class="row mb-3">
    <div class="col-md-3">
        <input type="date" id="start_date" class="form-control" placeholder="Start Date">
    </div>
    <div class="col-md-3">
        <input type="date" id="end_date" class="form-control" placeholder="End Date">
    </div>
    <div class="col-md-3">
        <input type="text" id="employee_id_search" class="form-control" placeholder="Search Employee ID">
    </div>
    <div class="col-md-3">
        <input type="text" id="employee_name_search" class="form-control" placeholder="Search Employee Name">
    </div>
</div>
<div class="d-flex justify-content-end">
        <button id="filter_button" class="btn btn-primary w-25">Filter</button>
</div>

    <table class="table table-bordered" id="attendanceTable">
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
@endsection


@section('scripts') 
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const today = new Date().toISOString().split('T')[0];
        const yesterday = new Date(new Date().setDate(new Date().getDate() - 1)).toISOString().split('T')[0];

        document.getElementById('start_date').value = yesterday;
        document.getElementById('end_date').value = today;
    });
</script>

<script>
$(document).ready(function () {
    var table = $('#attendanceTable').DataTable({
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
        dom: '<"row"<"col-md-6"i><"col-md-6"f>>t<"row"<"col-md-6"B><"col-md-6"p>>',
        buttons: [
            'excel', 'pdf', 'pageLength'
        ],
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
                render: function (data, type, row) {
                    return new Date(data)
                        .toLocaleString('en-US',
                            {
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
                render: function (data, type, row) {
                    return data == 1 ? 'Out' : 'In';
                }
            },
            {
                data: 'status2',
                name: 'status2',
                render: function (data, type, row) {
                    if (data == 15) return 'Face';
                    if (data == 25) return 'Palm';
                    else return data;
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