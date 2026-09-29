@extends('layouts.auth')

@section('title', 'Enter Verification Code')

@section('content')
    <h4 class="text-center mb-4">Enter Verification Code</h4>

    <p class="text-muted small mb-4">
        We sent a 6-digit verification code to {{ $email ?? 'your email address' }}.
        Enter it below to verify your account.
    </p>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if (session('warning'))
        <div class="alert alert-warning">{{ session('warning') }}</div>
    @endif

    @if (session('info'))
        <div class="alert alert-info">{{ session('info') }}</div>
    @endif

    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('verification-code.verify') }}">
        @csrf
        <div class="mb-3">
            <label for="code" class="form-label">Verification code</label>
            <input type="text" name="code" id="code" class="form-control text-center"
                inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                placeholder="••••••" autofocus required>
        </div>
        <button type="submit" class="btn btn-primary w-100 mb-3">
            Verify
        </button>
    </form>

    <form method="POST" action="{{ route('verification-code.resend') }}">
        @csrf
        <button type="submit" class="btn btn-link w-100 text-muted small">
            Didn't receive the code? Resend
        </button>
    </form>
@endsection
