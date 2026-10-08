@extends(auth()->user()->isParent() ? 'layouts.parent' : 'layouts.app')

@section('title', 'My Profile')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-person me-2"></i>My Profile</h4>
    </div>

    <div class="row g-3">
        {{-- Profile Info --}}
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0">Profile Information</h6></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('profile.update') }}">
                        @csrf @method('PUT')

                        <div class="mb-3">
                            <label for="name" class="form-label">Full Name</label>
                            <input type="text" class="form-control @error('name') is-invalid @enderror"
                                   id="name" name="name" value="{{ old('name', $user->name) }}" required>
                            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" class="form-control @error('email') is-invalid @enderror"
                                   id="email" name="email" value="{{ old('email', $user->email) }}" required>
                            @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-3">
                            <label for="phone" class="form-label">Phone Number</label>
                            <input type="text" class="form-control" id="phone" name="phone"
                                   value="{{ old('phone', $user->phone) }}">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Role</label>
                            <input type="text" class="form-control" value="{{ ucfirst($user->role) }}" disabled>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">School</label>
                            <input type="text" class="form-control" value="{{ $user->school?->name ?? 'Not assigned' }}" disabled>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle me-1"></i> Update Profile
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Change Password --}}
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0">Change Password</h6></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('profile.password') }}">
                        @csrf @method('PUT')

                        <div class="mb-3">
                            <label for="current_password" class="form-label">Current Password</label>
                            <input type="password" class="form-control @error('current_password') is-invalid @enderror"
                                   id="current_password" name="current_password" required>
                            @error('current_password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">New Password</label>
                            <input type="password" class="form-control @error('password') is-invalid @enderror"
                                   id="password" name="password" required>
                            @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-3">
                            <label for="password_confirmation" class="form-label">Confirm New Password</label>
                            <input type="password" class="form-control"
                                   id="password_confirmation" name="password_confirmation" required>
                        </div>

                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-shield-check me-1"></i> Change Password
                        </button>
                    </form>
                </div>
            </div>

            {{-- Account Info --}}
            <div class="card border-0 shadow-sm mt-3">
                <div class="card-header bg-white"><h6 class="mb-0">Account Information</h6></div>
                <div class="card-body">
                    <table class="table table-borderless table-sm mb-0">
                        <tr><th class="text-muted" width="40%">Member Since</th><td>{{ $user->created_at?->format('F d, Y') }}</td></tr>
                        <tr><th class="text-muted">Status</th><td>
                            @if($user->is_active)
                                <span class="badge bg-success">Active</span>
                            @else
                                <span class="badge bg-danger">Inactive</span>
                            @endif
                        </td></tr>
                        <tr><th class="text-muted">Last Updated</th><td>{{ $user->updated_at?->diffForHumans() }}</td></tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
