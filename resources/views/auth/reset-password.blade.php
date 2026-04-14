@extends('layouts.auth')

@section('title', 'Reset Password')

@section('content')
    <h4 class="text-center mb-4">Reset Password</h4>

    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div class="mb-3">
            <label for="email" class="form-label">Email Address</label>
            <input type="email" class="form-control @error('email') is-invalid @enderror"
                   id="email" name="email" value="{{ old('email') }}" required placeholder="your@email.com">
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">New Password</label>
            <input type="password" class="form-control @error('password') is-invalid @enderror"
                   id="password" name="password" required placeholder="Minimum 6 characters">
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="password_confirmation" class="form-label">Confirm New Password</label>
            <input type="password" class="form-control" id="password_confirmation"
                   name="password_confirmation" required>
        </div>

        <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-shield-check me-1"></i> Reset Password
        </button>
    </form>
@endsection
