@extends('layouts.auth')

@section('title', 'Register')

@section('content')
    <h4 class="text-center mb-4">Create Account</h4>

    <form method="POST" action="{{ route('register') }}">
        @csrf

        <div class="mb-3">
            <label for="name" class="form-label">Full Name</label>
            <input type="text" class="form-control @error('name') is-invalid @enderror"
                   id="name" name="name" value="{{ old('name') }}" required placeholder="Juan Dela Cruz">
            @error('name')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="email" class="form-label">Email Address</label>
            <input type="email" class="form-control @error('email') is-invalid @enderror"
                   id="email" name="email" value="{{ old('email') }}" required placeholder="your@email.com">
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="role" class="form-label">I am a...</label>
            <select class="form-select @error('role') is-invalid @enderror" id="role" name="role" required>
                <option value="">Select your role</option>
                <option value="teacher" {{ old('role') === 'teacher' ? 'selected' : '' }}>Teacher</option>
                <option value="parent" {{ old('role') === 'parent' ? 'selected' : '' }}>Parent / Guardian</option>
            </select>
            @error('role')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-2">
            <label for="password" class="form-label">Password</label>
            <x-password-input name="password" placeholder="Create a password" autocomplete="new-password" />
        </div>

        @include('auth._password-rules')

        <div class="mb-3">
            <label for="password_confirmation" class="form-label">Confirm Password</label>
            <x-password-input name="password_confirmation" placeholder="Type the same password again" autocomplete="new-password" />
        </div>

        <button type="submit" class="btn btn-primary w-100 mb-3">
            <i class="bi bi-person-plus me-1"></i> Register
        </button>

        <p class="text-center text-muted small mb-0">
            Already have an account? <a href="{{ route('login') }}">Login here</a>
        </p>
    </form>
@endsection
