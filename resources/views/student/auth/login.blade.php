@extends('layouts.auth')

@section('title', 'Student Login')

@section('body_class', 'student-login-bg')

@push('styles')
<link href="{{ asset('css/student.css') }}" rel="stylesheet">
<style>
    .student-login-bg {
        background: linear-gradient(135deg, var(--kid-primary) 0%, #8B83FF 50%, var(--kid-secondary) 100%);
    }
    body.student-login-bg > .container > .row > div > .text-center > .text-muted, body.student-login-bg > .container > .row > div > p.text-muted { color: rgba(255, 255, 255, 0.9) !important; }
    body.student-login-bg h1.text-primary { color: #fff !important; text-shadow: 0 2px 10px rgba(0,0,0,0.1); } .nav-pills .nav-link.text-muted { color: #6c757d !important; }
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
    @keyframes float { 0% { transform: translateY(0px); } 50% { transform: translateY(-10px); } 100% { transform: translateY(0px); } }
</style>
@endpush

@section('content')
    {{-- Unified Tab Navigation --}}
    <ul class="nav nav-pills nav-justified mb-4" style="background-color: #f8f9fa; border-radius: 50rem; padding: 0.3rem;">
        <li class="nav-item">
            <a class="nav-link text-muted" href="{{ route('login') }}" style="border-radius: 50rem; font-weight: 600;">
                <i class="bi bi-person-badge me-1"></i> Login
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link active shadow-sm" href="{{ route('student.login') }}" style="border-radius: 50rem; font-weight: 600; background-color: var(--kid-primary);">
                <i class="bi bi-emoji-smile me-1"></i> Learners
            </a>
        </li>
    </ul>

    <div class="text-center mb-2" style="animation: float 3s ease-in-out infinite;">
        {{-- If you download a custom GIF, uncomment the img tag below and delete the span --}}
        {{-- <img src="{{ asset('images/waving-avatar.gif') }}" alt="Waving Avatar" style="height: 80px;"> --}}
        <span style="font-size: 4rem;">🙋🏽‍♂️</span>
    </div>
    <h4 class="text-center mb-1" style="color: var(--kid-primary); font-weight: 800;">Welcome, Learner!</h4>
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
    const pinInput = document.getElementById('pinInput');
    const digits = document.querySelectorAll('.pin-digit');

    // Make sure pin is set before form submit
    document.getElementById('pinForm').addEventListener('submit', () => {
        pinInput.value = Array.from(digits).map(d => d.value).join('');
    });

    digits.forEach((input, index) => {
        input.addEventListener('input', (e) => {
            // Auto advance
            if (input.value && index < digits.length - 1) {
                digits[index + 1].focus();
            }

            // Auto-submit when all 6 digits are entered
            const filled = Array.from(digits).every(d => d.value.length === 1);
            if (filled) {
                pinInput.value = Array.from(digits).map(d => d.value).join('');
                setTimeout(() => {
                    document.getElementById('pinForm').submit();
                }, 200);
            }
        });

        // Handle backspace to go to previous input
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Backspace' && !input.value && index > 0) {
                digits[index - 1].focus();
            }
        });
    });
</script>
@endpush
