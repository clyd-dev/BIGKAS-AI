@extends('layouts.auth')

@section('title', 'Login')

@section('content')
    {{-- Unified Tab Navigation --}}
    <ul class="nav nav-pills nav-justified mb-4" style="background-color: #f8f9fa; border-radius: 50rem; padding: 0.3rem;">
        <li class="nav-item">
            <a class="nav-link active shadow-sm" href="{{ route('login') }}" style="border-radius: 50rem; font-weight: 600;">
                <i class="bi bi-person-badge me-1"></i> Login
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link text-muted" href="{{ route('student.login') }}" style="border-radius: 50rem; font-weight: 600;">
                <i class="bi bi-emoji-smile me-1"></i> Learners
            </a>
        </li>
    </ul>

    <h4 class="text-center mb-4">Welcome Back</h4>

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="mb-3">
            <label for="email" class="form-label">Email Address</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                <input type="email" class="form-control @error('email') is-invalid @enderror"
                       id="email" name="email" value="{{ old('email') }}" required autofocus
                       placeholder="your@email.com">
                @error('email')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                <input type="password" class="form-control @error('password') is-invalid @enderror"
                       id="password" name="password" required placeholder="Enter password">
                @error('password')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="remember" id="remember" {{ old('remember') ? 'checked' : '' }}>
                <label class="form-check-label small" for="remember">Remember me</label>
            </div>
            <a href="{{ route('password.request') }}" class="small text-decoration-none">Forgot password?</a>
        </div>

        <button type="submit" class="btn btn-primary w-100 mb-3">
            <i class="bi bi-box-arrow-in-right me-1"></i> Login
        </button>

        <p class="text-center text-muted small mb-0">
            Don't have an account? <a href="{{ route('register') }}">Register here</a>
        </p>
    </form>
@endsection
