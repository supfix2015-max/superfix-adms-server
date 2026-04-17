@extends('layouts.app')

@section('content')
<div class="container">
    <h2>Users</h2>
    @if(session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif

    @if (session('failed'))
        <div class="alert alert-danger">
            {{ session('failed') }}
        </div>
    @endif
    <hr>

    <form action="{{ route('users.store') }}" method="POST" class="container border rounded p-4 bg-light">
        @csrf
        <div class="row mb-3">
            <div class="col-md-6">
                <div class="form-group">
                    <label for="name" class="form-label">Name</label>
                    <input type="text" class="form-control" id="name" name="name" placeholder="Enter Name">
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" class="form-control" id="email" name="email" placeholder="Enter Email">
                </div>
            </div>
        </div>
        <div class="row mb-3">
            <div class="col-md-6">
                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password"
                        placeholder="Enter Password">
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group form-check col">
                    <p>&#160;</p>
                    <input class="form-check-input" type="checkbox" value="1" id="isAdmin" name="isAdmin">
                    <label class="form-check-label" for="isAdmin">
                        Is Super Admin?
                    </label>
                </div>
            </div>
        </div>
        <div class="d-flex">
            <div>

            </div>
            <div class="ms-auto">
                <button type="submit" class="btn btn-primary pull-right">Create</button>
            </div>
        </div>
    </form>




    <table class="table table-bordered">
        <thead>
            <tr>
                <th>Admin Name</th>
                <th>Employee Email</th>
                <th>Is Admin</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            @foreach($users as $user)
                <tr>
                    <td>{{ $user->name}}</td>
                    <td>{{ $user->email }}</td>
                    <td>{{ $user->is_admin ? 'Yes' : 'No' }}</td>
                    <td>
                        <form action="{{ route('users.destroy', $user->id) }}" method="POST">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
            @endforeach
        </tbody>

    </table>
</div>
@endsection