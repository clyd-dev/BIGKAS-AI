@extends('layouts.app')

@section('title', 'Manage Users')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-person-badge me-2"></i>Manage Users</h4>
        <a href="{{ route('admin.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Admin Panel</a>
    </div>

    {{-- Filters --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.users') }}" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Search</label>
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Name or email..." value="{{ request('search') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Role</label>
                    <select name="role" class="form-select form-select-sm">
                        <option value="">All Roles</option>
                        <option value="admin" {{ request('role') === 'admin' ? 'selected' : '' }}>Admin</option>
                        <option value="teacher" {{ request('role') === 'teacher' ? 'selected' : '' }}>Teacher</option>
                        <option value="parent" {{ request('role') === 'parent' ? 'selected' : '' }}>Parent</option>
                        <option value="student" {{ request('role') === 'student' ? 'selected' : '' }}>Student</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-search me-1"></i> Filter</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Users Table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>School</th>
                            <th>Status</th>
                            <th>Joined</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($users ?? [] as $user)
                            <tr>
                                <td><strong>{{ $user->name }}</strong></td>
                                <td>{{ $user->email }}</td>
                                <td>
                                    <form method="POST" action="{{ route('admin.users.role', $user) }}" class="d-inline">
                                        @csrf
                                        <select name="role" class="form-select form-select-sm d-inline-block" style="width: auto;" onchange="this.form.submit()">
                                            @foreach(['admin', 'teacher', 'parent', 'student'] as $role)
                                                <option value="{{ $role }}" {{ $user->role === $role ? 'selected' : '' }}>{{ ucfirst($role) }}</option>
                                            @endforeach
                                        </select>
                                    </form>
                                </td>
                                <td>{{ $user->school?->name ?? '-' }}</td>
                                <td>
                                    @if($user->is_active)
                                        <span class="badge bg-success">Active</span>
                                    @else
                                        <span class="badge bg-danger">Inactive</span>
                                    @endif
                                </td>
                                <td>{{ $user->created_at?->format('M d, Y') }}</td>
                                <td class="text-end">
                                    @if($user->is_active)
                                        <form method="POST" action="{{ route('admin.users.deactivate', $user) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Deactivate" onclick="return confirm('Deactivate this user?')">
                                                <i class="bi bi-person-x"></i>
                                            </button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('admin.users.activate', $user) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-success" title="Activate">
                                                <i class="bi bi-person-check"></i>
                                            </button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('admin.users.reset-password', $user) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-warning" title="Reset Password" onclick="return confirm('Reset password for {{ $user->name }}?')">
                                            <i class="bi bi-key"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">No users found</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @if(method_exists($users ?? collect(), 'links'))
        <div class="mt-3">{{ $users->links() }}</div>
    @endif
@endsection
