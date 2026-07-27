{{-- Student PIN Login Page --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Student Login - BIGKAS-AI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="{{ asset('css/student.css') }}" rel="stylesheet">
</head>
<body>
    <div class="login-container">
        <div class="login-card">
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

            {{-- Logo --}}
            <div class="mb-3">
                <span style="font-size: 3rem;">📖</span>
            </div>
            <h1>BIGKAS-AI</h1>
            <p class="text-muted mb-4">Enter your 6-digit PIN to start reading!</p>

            {{-- Flash Messages --}}
            @if(session('error'))
                <div class="alert alert-danger student-alert py-2 px-3 mb-3" style="font-size: 0.85rem;">
                    {{ session('error') }}
                </div>
            @endif
            @if(session('success'))
                <div class="alert alert-success student-alert py-2 px-3 mb-3" style="font-size: 0.85rem;">
                    {{ session('success') }}
                </div>
            @endif

            <form method="POST" action="{{ route('student.login.submit') }}" id="pinForm">
                @csrf

                {{-- Hidden field that collects the full PIN --}}
                <input type="hidden" name="pin" id="pinInput" value="">

                {{-- 6 individual digit inputs --}}
                <div class="pin-input-group">
                    <input type="text" class="pin-digit" inputmode="numeric" maxlength="1" autocomplete="off" autofocus>
                    <input type="text" class="pin-digit" inputmode="numeric" maxlength="1" autocomplete="off">
                    <input type="text" class="pin-digit" inputmode="numeric" maxlength="1" autocomplete="off">
                    <input type="text" class="pin-digit" inputmode="numeric" maxlength="1" autocomplete="off">
                    <input type="text" class="pin-digit" inputmode="numeric" maxlength="1" autocomplete="off">
                    <input type="text" class="pin-digit" inputmode="numeric" maxlength="1" autocomplete="off">
                </div>

                @error('pin')
                    <div class="text-danger mb-3" style="font-size: 0.85rem;">{{ $message }}</div>
                @enderror

                <button type="submit" class="btn btn-kid btn-kid-primary w-100 py-3" id="loginBtn">
                    <i class="bi bi-box-arrow-in-right"></i>
                    Let's Go!
                </button>
            </form>

            <p class="text-muted mt-4 mb-0" style="font-size: 0.8rem;">
                Ask your teacher for your PIN code
            </p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="{{ asset('js/student.js') }}"></script>
    <script>
        // Auto-submit when all 6 digits are entered
        document.querySelectorAll('.pin-digit').forEach((input, index, all) => {
            input.addEventListener('input', () => {
                const filled = Array.from(all).every(d => d.value.length === 1);
                if (filled) {
                    // Small delay so user sees the last digit appear
                    setTimeout(() => {
                        document.getElementById('pinForm').submit();
                    }, 200);
                }
            });
        });
    </script>
</body>
</html>
