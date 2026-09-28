@extends('layouts.auth')

@section('title', 'Verify Email')

@section('content')
    <h4 class="text-center mb-4">Verify Your Email</h4>

    <p class="text-muted small mb-4">
        Thanks for signing up! Before getting started, please verify your email address
        by clicking on the link we just emailed to you. If you didn't receive the email,
        we will gladly send you another.
    </p>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <button type="submit" class="btn btn-primary w-100 mb-3">
            Resend Verification Email
        </button>
    </form>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="btn btn-link w-100 text-muted small">Log out</button>
    </form>
@endsection
