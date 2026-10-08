@extends('layouts.app')

@section('title', 'Manage Users')

@section('content')
    <x-page-header title="Manage Users" icon="bi-person-badge">
        <x-slot:actions>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createUserModal">
                <i class="bi bi-person-plus me-1"></i> Create User
            </button>
        </x-slot:actions>
    </x-page-header>


    {{-- Attention: teachers who still need a grade & section --}}
    @if($pendingTeachers > 0 && ! request()->boolean('unassigned'))
        <div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <i class="bi bi-exclamation-triangle me-2"></i>
                <strong>{{ $pendingTeachers }}</strong> teacher{{ $pendingTeachers === 1 ? '' : 's' }} waiting for a grade &amp; section.
            </div>
            <a href="{{ route('admin.users', ['role' => 'teacher', 'unassigned' => 1]) }}" class="btn btn-sm btn-warning">Show them</a>
        </div>
    @endif
    @if(request()->boolean('unassigned'))
        <div class="alert alert-info d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div><i class="bi bi-funnel me-2"></i>Showing teachers without a grade &amp; section. Use <strong>Assign</strong> on a row to give one.</div>
            <a href="{{ route('admin.users') }}" class="btn btn-sm btn-outline-secondary">Show all users</a>
        </div>
    @endif

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
                        @foreach(['admin','teacher','parent'] as $r)
                            <option value="{{ $r }}" {{ request('role') === $r ? 'selected' : '' }}>{{ ucfirst($r) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Status</option>
                        <option value="active"   {{ request('status') === 'active'   ? 'selected' : '' }}>Active</option>
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

    {{-- Summary --}}
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
                            <th>Grade &amp; Section</th>
                            <th>Status</th>
                            <th>Joined</th>
                            <th>Last Login</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($users as $user)
                            @php
                                $userClass = $teacherClasses[$user->id] ?? null;
                            @endphp
                            <tr>
                                <td>
                                    <strong>{{ $user->name }}</strong>
                                    @if($user->created_at?->gt(now()->subDays(7)))
                                        <span class="badge bg-info ms-1">New</span>
                                    @endif
                                </td>
                                <td class="text-muted small">{{ $user->email }}</td>

                                {{-- Role: static badge, edited via modal --}}
                                <td>
                                    @php
                                        $roleColors = ['admin'=>'danger','teacher'=>'primary','parent'=>'success','student'=>'warning'];
                                        $roleColor  = $roleColors[$user->role] ?? 'secondary';
                                    @endphp
                                    <span class="badge bg-{{ $roleColor }}">{{ ucfirst($user->role) }}</span>
                                </td>

                                {{-- Grade & Section --}}
                                <td class="small">
                                    @if($userClass)
                                        <span class="badge bg-primary bg-opacity-10 text-primary">
                                            Gr.{{ $userClass->grade_level }} – {{ $userClass->section }}
                                        </span>
                                    @elseif($user->role === 'teacher')
                                        <button type="button" class="btn btn-sm btn-warning py-0"
                                                data-bs-toggle="modal" data-bs-target="#editUser{{ $user->id }}">
                                            <i class="bi bi-plus-circle me-1"></i>Assign
                                        </button>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>

                                <td>
                                    @if($user->is_active)
                                        <span class="badge bg-success">Active</span>
                                    @else
                                        <span class="badge bg-danger">Inactive</span>
                                    @endif
                                </td>
                                <td class="small text-muted">{{ $user->created_at?->format('M d, Y') }}</td>
                                <td class="small text-muted">{{ $user->last_login_at?->diffForHumans() ?? 'Never' }}</td>

                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                            data-bs-toggle="modal" data-bs-target="#editUser{{ $user->id }}">
                                        <i class="bi bi-pencil me-1"></i>Edit
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                            data-bs-toggle="modal" data-bs-target="#userDanger{{ $user->id }}">
                                        <i class="bi bi-exclamation-triangle me-1"></i>Danger zone
                                    </button>
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

    <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
        <div class="small text-muted">
            @if($users->total() > 0)
                Showing {{ $users->firstItem() }}–{{ $users->lastItem() }} of {{ $users->total() }}
            @endif
        </div>
        {{ $users->onEachSide(1)->links('partials.pagination') }}
    </div>

    {{-- ═══════════════════════════════════════════════════════
         CREATE USER MODAL
    ═══════════════════════════════════════════════════════ --}}
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
                                    @foreach(['admin','teacher','parent'] as $r)
                                        <option value="{{ $r }}" {{ old('role') === $r ? 'selected' : '' }}>
                                            {{ ucfirst($r) }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('role') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-6" data-class-wrap>
                                <label class="form-label fw-semibold">Grade &amp; Section</label>
                                <select name="class_id" class="form-select">
                                    <option value="">— None —</option>
                                    @foreach($allClasses as $cls)
                                        <option value="{{ $cls->id }}" {{ $cls->teacher_id ? 'disabled' : '' }} {{ old('class_id') == $cls->id ? 'selected' : '' }}>
                                            Gr.{{ $cls->grade_level }} – {{ $cls->section }}@if($cls->teacher_id) — has a teacher @endif
                                        </option>
                                    @endforeach
                                </select>
                                @error('class_id') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                                <div class="form-text">The class this teacher advises.</div>
                            </div>
                        </div>
                        <div class="mb-2">
                            <label for="create_user_password" class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
                            <x-password-input name="password" id="create_user_password" autocomplete="new-password" />
                        </div>

                        @include('auth._password-rules', ['passwordId' => 'create_user_password', 'confirmId' => 'create_user_password_confirmation'])

                        <div class="mb-3">
                            <label for="create_user_password_confirmation" class="form-label fw-semibold">Confirm Password <span class="text-danger">*</span></label>
                            <x-password-input name="password_confirmation" id="create_user_password_confirmation" autocomplete="new-password" />
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

    {{-- ═══════════════════════════════════════════════════════
         EDIT USER MODALS (one per user)
    ═══════════════════════════════════════════════════════ --}}
    @foreach($users as $user)
        @php $userClass = $teacherClasses[$user->id] ?? null; @endphp
        <div class="modal fade" id="editUser{{ $user->id }}" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="{{ route('admin.users.update', $user) }}">
                        @csrf @method('PUT')
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-pencil me-1"></i>Edit User
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted small mb-3">
                                <i class="bi bi-person me-1"></i>
                                <strong>{{ $user->name }}</strong> &mdash; {{ $user->email }}
                            </p>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                                <select name="role" class="form-select" required>
                                    @foreach(['admin','teacher','parent'] as $r)
                                        <option value="{{ $r }}" {{ $user->role === $r ? 'selected' : '' }}>
                                            {{ ucfirst($r) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="mb-3" data-class-wrap>
                                <label class="form-label fw-semibold">Grade &amp; Section</label>
                                <select name="class_id" class="form-select">
                                    <option value="">— None —</option>
                                    @foreach($allClasses as $cls)
                                        @php $takenByOther = $cls->teacher_id && $cls->teacher_id !== $user->id; @endphp
                                        <option value="{{ $cls->id }}" {{ $takenByOther ? 'disabled' : '' }}
                                            {{ $userClass && $userClass->id === $cls->id ? 'selected' : '' }}>
                                            Gr.{{ $cls->grade_level }} – {{ $cls->section }}
                                            @if($takenByOther)
                                                — has a teacher ({{ $cls->teacher->name ?? '?' }})
                                            @endif
                                        </option>
                                    @endforeach
                                </select>
                                <div class="form-text">A section has one teacher; sections that already have one can't be picked.</div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i>Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endforeach

    {{-- ═══════════════════════════════════════════════════════
         DANGER ZONE MODALS (one per user)
    ═══════════════════════════════════════════════════════ --}}
    @foreach($users as $user)
        <div class="modal fade" id="userDanger{{ $user->id }}" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Danger zone</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted mb-3">
                            <strong>{{ $user->name }}</strong> &mdash; {{ $user->email }} ({{ ucfirst($user->role) }})
                        </p>

                        {{-- Account status --}}
                        @if($user->is_active)
                            <div class="border border-danger rounded p-3 bg-danger bg-opacity-10 mb-3">
                                <div class="fw-semibold text-danger">Deactivate account</div>
                                <p class="small mb-2">{{ $user->name }} will no longer be able to log in. Their data is kept, and you can activate the account again.</p>
                                @if($user->id === auth()->id())
                                    <div class="small text-muted">You can't deactivate your own account.</div>
                                @else
                                    <form method="POST" action="{{ route('admin.users.deactivate', $user) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-person-x me-1"></i>Deactivate account</button>
                                    </form>
                                @endif
                            </div>
                        @else
                            <div class="border border-success rounded p-3 bg-success bg-opacity-10 mb-3">
                                <div class="fw-semibold text-success">Account is inactive</div>
                                <p class="small mb-2">{{ $user->name }} cannot log in. Activate the account to let them in again.</p>
                                <form method="POST" action="{{ route('admin.users.activate', $user) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-person-check me-1"></i>Activate account</button>
                                </form>
                            </div>
                        @endif

                        {{-- Password --}}
                        <div class="border border-warning rounded p-3 bg-warning bg-opacity-10">
                            <div class="fw-semibold">Reset password</div>
                            <p class="small mb-2">
                                Replaces {{ $user->name }}'s password with a temporary one, shown to you once after you confirm.
                                Ask them to change it right away under Profile &rarr; Change Password.
                            </p>
                            <form method="POST" action="{{ route('admin.users.reset-password', $user) }}"
                                  onsubmit="return confirm('Reset the password for {{ addslashes($user->name) }}?')">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-warning"><i class="bi bi-key me-1"></i>Reset password</button>
                            </form>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    @push('scripts')
    <script>
        // Grade & Section only applies to teachers: hide (and don't submit) it for any other role.
        document.querySelectorAll('form select[name="role"]').forEach(function (role) {
            var wrap = role.closest('form').querySelector('[data-class-wrap]');
            if (!wrap) return;
            var cls = wrap.querySelector('select');
            function sync() {
                var show = role.value === 'teacher';
                wrap.classList.toggle('d-none', !show);
                cls.disabled = !show;
            }
            role.addEventListener('change', sync);
            sync();
        });
    </script>
    @endpush

    {{-- Re-open create modal on validation error --}}
    @if($errors->any())
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                new bootstrap.Modal(document.getElementById('createUserModal')).show();
            });
        </script>
    @endif
@endsection
