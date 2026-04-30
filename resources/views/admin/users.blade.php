@extends('layouts.app')

@section('title', 'Manage Users')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-person-badge me-2"></i>Manage Users</h4>
        <div class="d-flex gap-2">
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createUserModal">
                <i class="bi bi-person-plus me-1"></i> Create User
            </button>
            <a href="{{ route('admin.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Admin Panel
            </a>
        </div>
    </div>

    {{-- Filters --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('admin.users') }}" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control form-control-sm"
                           placeholder="Search name or email..." value="{{ request('search') }}">
                </div>
                <div class="col-md-2">
                    <select name="role" class="form-select form-select-sm">
                        <option value="">All Roles</option>
                        @foreach(['admin','teacher','parent','student'] as $r)
                            <option value="{{ $r }}" {{ request('role') === $r ? 'selected' : '' }}>{{ ucfirst($r) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Status</option>
                        <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-search"></i> Filter
                    </button>
                    <a href="{{ route('admin.users') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Summary badges --}}
    <div class="d-flex gap-2 mb-3 flex-wrap">
        <span class="badge bg-secondary px-3 py-2">Total: {{ $users->total() }}</span>
    </div>

    {{-- Users Table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>School</th>
                            <th>Status</th>
                            <th>Joined</th>
                            <th>Last Login</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($users as $user)
                            <tr>
                                <td>
                                    <strong>{{ $user->name }}</strong>
                                </td>
                                <td class="text-muted small">{{ $user->email }}</td>
                                <td>
                                    <form method="POST" action="{{ route('admin.users.role', $user) }}" class="d-inline">
                                        @csrf
                                        <select name="role" class="form-select form-select-sm d-inline-block"
                                                style="width: auto;" onchange="this.form.submit()">
                                            @foreach(['admin','teacher','parent','student'] as $r)
                                                <option value="{{ $r }}" {{ $user->role === $r ? 'selected' : '' }}>
                                                    {{ ucfirst($r) }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </form>
                                </td>
                                <td class="small">{{ $user->school?->name ?? '-' }}</td>
                                <td>
                                    @if($user->is_active)
                                        <span class="badge bg-success">Active</span>
                                    @else
                                        <span class="badge bg-danger">Inactive</span>
                                    @endif
                                </td>
                                <td class="small text-muted">{{ $user->created_at?->format('M d, Y') }}</td>
                                <td class="small text-muted">{{ $user->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                                <td class="text-end">
                                    <div class="d-flex gap-1 justify-content-end">
                                        {{-- Activate / Deactivate --}}
                                        @if($user->is_active)
                                            <form method="POST" action="{{ route('admin.users.deactivate', $user) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                                        title="Deactivate"
                                                        onclick="return confirm('Deactivate {{ addslashes($user->name) }}?')">
                                                    <i class="bi bi-person-x"></i>
                                                </button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('admin.users.activate', $user) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-success" title="Activate">
                                                    <i class="bi bi-person-check"></i>
                                                </button>
                                            </form>
                                        @endif

                                        {{-- Reset Password --}}
                                        <form method="POST" action="{{ route('admin.users.reset-password', $user) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-warning"
                                                    title="Reset Password to Bigkas@123"
                                                    onclick="return confirm('Reset password for {{ addslashes($user->name) }}? New password: Bigkas@123')">
                                                <i class="bi bi-key"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-5">
                                    <i class="bi bi-people display-6 d-block mb-2"></i>
                                    No users found
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-3">{{ $users->withQueryString()->links() }}</div>

    {{-- Create User Modal --}}
    <div class="modal fade" id="createUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="{{ route('admin.users.create') }}">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-person-plus me-1"></i> Create New User</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control @error('name') is-invalid @enderror"
                                   name="name" value="{{ old('name') }}" required>
                            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
                            <input type="email" class="form-control @error('email') is-invalid @enderror"
                                   name="email" value="{{ old('email') }}" required>
                            @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                                <select name="role" class="form-select @error('role') is-invalid @enderror" required>
                                    <option value="">Select role...</option>
                                    @foreach(['admin','teacher','parent','student'] as $r)
                                        <option value="{{ $r }}" {{ old('role') === $r ? 'selected' : '' }}>
                                            {{ ucfirst($r) }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('role') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold">School</label>
                                <select name="school_id" class="form-select">
                                    <option value="">No school</option>
                                    @foreach($schools ?? [] as $school)
                                        <option value="{{ $school->id }}" {{ old('school_id') == $school->id ? 'selected' : '' }}>
                                            {{ $school->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control @error('password') is-invalid @enderror"
                                   name="password" required minlength="8">
                            @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Confirm Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" name="password_confirmation" required minlength="8">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-person-check me-1"></i> Create User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Re-open modal on validation error --}}
    @if($errors->any())
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                new bootstrap.Modal(document.getElementById('createUserModal')).show();
            });
        </script>
    @endif
@endsection
