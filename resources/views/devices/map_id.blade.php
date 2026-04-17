@extends('layouts.app')

@section('content')
<div class="container">
    <h2>Map Id With Names</h2>
    <p class="h6 text p ul">Providing the Same ID will replace the Name of Employee</p>
    <hr>

    <form action="{{ route('employee.store') }}" method="POST" class="container border rounded p-4 bg-light">
        @csrf
        <div class="row mb-3">
            <div class="col-md-6">
                <div class="form-group">
                    <label for="employee_id" class="form-label">Employee ID</label>
                    <input type="number" class="form-control" id="employee_id" name="employee_id"
                        placeholder="Enter Employee ID">
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group">
                    <label for="employee_name" class="form-label">Employee Name</label>
                    <input type="text" class="form-control" id="employee_name" name="employee_name"
                        placeholder="Enter Employee Name">
                </div>
            </div>
        </div>
        <div class="d-flex">
            <div>

            </div>
            <div class="ms-auto">
                <button type="submit" class="btn btn-primary pull-right">Submit</button>
            </div>
        </div>

    </form>



    <table class="table table-bordered">
        <thead>
            <tr>
                <th>Employee ID</th>
                <th>Employee Name</th>
            </tr>
        </thead>
        <tbody>
            @foreach($employees as $employee)
                <tr>
                    <td>{{ $employee->employee_id }}</td>
                    <td>
                        @if (!empty($employee->name))
                            {{ $employee->name }}
                        @else
                            {{ 'N/A' }}
                        @endif
                    </td>

                </tr>
            @endforeach
        </tbody>

    </table>
    <div class="pagination">
        {{ $employees->links('pagination::bootstrap-5') }}
    </div>
</div>
@endsection