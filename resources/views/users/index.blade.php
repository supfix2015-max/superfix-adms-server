@extends('layouts.app')

@section('title', 'Admin Users')

@section('content')
    <div class="card mb-4">
        <div class="card-body">
            <div class="page-toolbar">
                <div>
                    <span class="eyebrow text-primary fw-bold text-uppercase small">Administration</span>
                    <h1 class="mt-2 mb-1">Operator Accounts</h1>
                    <p class="small mb-0">Create and remove privileged users who can access the ADMS enterprise workspace.</p>
                </div>
                <div class="stat-pill">
                    <span>Managed Accounts</span>
                    <strong>{{ $users->count() }}</strong>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-4">
            <div class="card shell-card">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">Create Operator</h5>
                        <div class="shell-subtitle">Provision a new login for administrators or support staff.</div>
                    </div>
                </div>
                <div class="card-body">
                    <form action="{{ route('users.store') }}" method="POST" class="panel-stack">
                        @csrf
                        <div>
                            <label for="name" class="form-label fw-semibold">Name</label>
                            <input type="text" class="form-control" id="name" name="name" placeholder="Operator name" required>
                        </div>
                        <div>
                            <label for="email" class="form-label fw-semibold">Email</label>
                            <input type="email" class="form-control" id="email" name="email" placeholder="operator@example.com" required>
                        </div>
                        <div>
                            <label for="password" class="form-label fw-semibold">Password</label>
                            <input type="password" class="form-control" id="password" name="password" placeholder="Temporary password" required>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="1" id="isAdmin" name="isAdmin">
                            <label class="form-check-label fw-semibold" for="isAdmin">Grant super admin access</label>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Create User</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-8">
            <div class="card table-shell">
                <div class="card-header">
                    <div>
                        <h5 class="shell-title">Existing Operators</h5>
                        <div class="shell-subtitle">Administrative accounts excluding the current session user.</div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Access</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($users as $user)
                                    <tr>
                                        <td class="fw-semibold">{{ $user->name }}</td>
                                        <td>{{ $user->email }}</td>
                                        <td>
                                            <span class="badge bg-{{ $user->is_admin ? 'success' : 'secondary' }}">
                                                {{ $user->is_admin ? 'Super Admin' : 'Standard Admin' }}
                                            </span>
                                        </td>
                                        <td>
                                            <form action="{{ route('users.destroy', $user->id) }}" method="POST" onsubmit="return confirm('Delete this user?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4">
                                            <div class="empty-state">
                                                <i class="bi bi-person-x"></i>
                                                <div class="fw-semibold">No additional users found</div>
                                                <div class="small">Create another operator account to share workspace access.</div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
