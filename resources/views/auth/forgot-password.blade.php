@extends('layouts.auth')

@section('title', 'Forgot Password')

@section('content')
    <h4 class="text-center mb-3">Forgot Password</h4>
    <p class="text-center text-muted small mb-4">Enter your email and we'll send you a reset link.</p>

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <div class="mb-3">
            <label for="email" class="form-label">Email Address</label>
            <input type="email" class="form-control @error('email') is-invalid @enderror"
                   id="email" name="email" value="{{ old('email') }}" required autofocus
                   placeholder="your@email.com">
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <button type="submit" class="btn btn-primary w-100 mb-3">
            <i class="bi bi-envelope me-1"></i> Send Reset Link
        </button>

        <p class="text-center small mb-0">
            <a href="{{ route('login') }}"><i class="bi bi-arrow-left me-1"></i>Back to Login</a>
        </p>
    </form>
@endsection
