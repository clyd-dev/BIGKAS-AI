@extends('layouts.auth')

@section('title', 'Student Login')

@section('body_class', 'student-login-bg')

@push('styles')
<link href="{{ asset('css/student.css') }}" rel="stylesheet">
<style>
    .student-login-bg {
        background: linear-gradient(135deg, var(--kid-primary) 0%, #8B83FF 50%, var(--kid-secondary) 100%);
    }
    .student-login-bg .text-muted {
        color: rgba(255, 255, 255, 0.8) !important;
    }
    .student-login-bg .text-primary {
        color: #fff !important;
    }
    /* Pin input styles */
    .pin-input-group {
        display: flex;
        gap: 8px;
        justify-content: center;
        margin: 1.5rem 0;
    }
    .pin-digit {
        width: 48px;
        height: 56px;
        text-align: center;
        font-size: 1.5rem;
        font-weight: 800;
        border: 3px solid #dee2e6;
        border-radius: 12px;
        outline: none;
        transition: all 0.2s;
        color: var(--kid-primary);
    }
    .pin-digit:focus {
        border-color: var(--kid-primary);
        box-shadow: 0 0 0 3px var(--kid-primary-light);
    }
</style>
@endpush

@section('content')
    {{-- Unified Tab Navigation --}}
    <ul class="nav nav-pills nav-justified mb-4" style="background-color: #f8f9fa; border-radius: 50rem; padding: 0.3rem;">
        <li class="nav-item">
            <a class="nav-link text-muted" href="{{ route('login') }}" style="border-radius: 50rem; font-weight: 600;">
                <i class="bi bi-person-badge me-1"></i> Staff & Parents
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link active shadow-sm" href="{{ route('student.login') }}" style="border-radius: 50rem; font-weight: 600; background-color: var(--kid-primary);">
                <i class="bi bi-emoji-smile me-1"></i> Learners (PIN)
            </a>
        </li>
    </ul>

    <h4 class="text-center mb-4">Welcome, Learner!</h4>
    <p class="text-center text-muted small mb-4">Enter your 6-digit PIN to start reading.</p>

    <form method="POST" action="{{ route('student.login.submit') }}" id="pinForm">
        @csrf
        <input type="hidden" name="pin" id="pinInput" value="">

        <div class="pin-input-group">
            <input type="text" class="pin-digit" inputmode="numeric" maxlength="1" autocomplete="off" autofocus>
            <input type="text" class="pin-digit" inputmode="numeric" maxlength="1" autocomplete="off">
            <input type="text" class="pin-digit" inputmode="numeric" maxlength="1" autocomplete="off">
            <input type="text" class="pin-digit" inputmode="numeric" maxlength="1" autocomplete="off">
            <input type="text" class="pin-digit" inputmode="numeric" maxlength="1" autocomplete="off">
            <input type="text" class="pin-digit" inputmode="numeric" maxlength="1" autocomplete="off">
        </div>

        @error('pin')
            <div class="text-danger text-center mb-3 small">{{ $message }}</div>
        @enderror

        <button type="submit" class="btn btn-primary w-100 py-2 mt-2 fw-bold" style="background-color: var(--kid-primary); border-color: var(--kid-primary); border-radius: 12px;">
            <i class="bi bi-box-arrow-in-right me-1"></i> Let's Go!
        </button>
    </form>
@endsection

@push('scripts')
<script>
    // Auto-submit when all 6 digits are entered
    document.querySelectorAll('.pin-digit').forEach((input, index, all) => {
        input.addEventListener('input', () => {
            const filled = Array.from(all).every(d => d.value.length === 1);
            if (filled) {
                setTimeout(() => {
                    document.getElementById('pinForm').submit();
                }, 200);
            }
        });
    });
</script>
@endpush