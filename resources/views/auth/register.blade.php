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
                <option value="student" {{ old('role') === 'student' ? 'selected' : '' }}>Student / Learner</option>
            </select>
            @error('role')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="school_id" class="form-label">School (Optional)</label>
            <select class="form-select" id="school_id" name="school_id">
                <option value="">Select school</option>
                @foreach($schools ?? [] as $school)
                    <option value="{{ $school->id }}" {{ old('school_id') == $school->id ? 'selected' : '' }}>
                        {{ $school->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <input type="password" class="form-control @error('password') is-invalid @enderror"
                   id="password" name="password" required placeholder="Minimum 6 characters">
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="password_confirmation" class="form-label">Confirm Password</label>
            <input type="password" class="form-control" id="password_confirmation"
                   name="password_confirmation" required placeholder="Repeat password">
        </div>

        <button type="submit" class="btn btn-primary w-100 mb-3">
            <i class="bi bi-person-plus me-1"></i> Register
        </button>

        <p class="text-center text-muted small mb-0">
            Already have an account? <a href="{{ route('login') }}">Login here</a>
        </p>
    </form>
@endsection
