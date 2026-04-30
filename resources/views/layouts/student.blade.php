<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'BIGKAS-AI') - Student Portal</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <!-- Student Portal CSS -->
    <link href="{{ asset('css/student.css') }}" rel="stylesheet">

    @stack('styles')
</head>
<body>

    {{-- Top bar with learner info --}}
    @isset($currentLearner)
    <nav class="student-topbar">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a href="{{ route('student.dashboard') }}" class="student-brand">
                <span class="brand-icon">📖</span>
                <span class="brand-text">BIGKAS-AI</span>
            </a>
            <div class="d-flex align-items-center gap-3">
                <div class="streak-pill" title="Daily Streak">
                    <span class="streak-fire">🔥</span>
                    <span class="streak-count">{{ $currentLearner->current_streak }}</span>
                </div>
                <div class="xp-pill" title="Total XP">
                    <span class="xp-star">⭐</span>
                    <span class="xp-count">{{ number_format($currentLearner->total_xp) }}</span>
                </div>
                <div class="dropdown">
                    <button class="student-avatar-btn dropdown-toggle" data-bs-toggle="dropdown">
                        <div class="student-avatar">{{ strtoupper(substr($currentLearner->first_name, 0, 1)) }}</div>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text fw-bold">{{ $currentLearner->first_name }}</span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="POST" action="{{ route('student.logout') }}">
                                @csrf
                                <button type="submit" class="dropdown-item text-danger">
                                    <i class="bi bi-box-arrow-right me-1"></i> Log Out
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>
    @endisset

    {{-- Flash messages --}}
    @if(session('success') || session('error') || session('info'))
    <div class="container-fluid mt-2 px-3">
        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show student-alert" role="alert">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif
        @if(session('error'))
            <div class="alert alert-danger alert-dismissible fade show student-alert" role="alert">
                {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif
        @if(session('info'))
            <div class="alert alert-info alert-dismissible fade show student-alert" role="alert">
                {{ session('info') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif
    </div>
    @endif

    {{-- Main content --}}
    <main class="student-main">
        @yield('content')
    </main>

    {{-- Bottom navigation (mobile friendly) --}}
    @isset($currentLearner)
    <nav class="student-bottomnav">
        <a href="{{ route('student.dashboard') }}" class="bottomnav-item {{ request()->routeIs('student.dashboard') ? 'active' : '' }}">
            <i class="bi bi-house-fill"></i>
            <span>Home</span>
        </a>
        <a href="{{ route('student.activities') }}" class="bottomnav-item {{ request()->routeIs('student.activities*') ? 'active' : '' }}">
            <i class="bi bi-controller"></i>
            <span>Activities</span>
        </a>
        <a href="{{ route('student.flashcards') }}" class="bottomnav-item {{ request()->routeIs('student.flashcards*') ? 'active' : '' }}">
            <i class="bi bi-stack"></i>
            <span>Cards</span>
        </a>
        <a href="{{ route('student.badges') }}" class="bottomnav-item {{ request()->routeIs('student.badges') ? 'active' : '' }}">
            <i class="bi bi-award-fill"></i>
            <span>Badges</span>
        </a>
        <a href="{{ route('student.leaderboard') }}" class="bottomnav-item {{ request()->routeIs('student.leaderboard') ? 'active' : '' }}">
            <i class="bi bi-trophy-fill"></i>
            <span>Rank</span>
        </a>
    </nav>
    @endisset

    {{-- Celebration overlay (hidden by default) --}}
    <div id="celebrationOverlay" class="celebration-overlay d-none">
        <div class="celebration-content">
            <div class="celebration-emoji">🎉</div>
            <h2 class="celebration-title"></h2>
            <p class="celebration-subtitle"></p>
            <button class="btn btn-light btn-lg mt-3 celebration-dismiss" onclick="dismissCelebration()">Awesome!</button>
        </div>
        <div class="confetti-container" id="confettiContainer"></div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="{{ asset('js/student.js') }}"></script>

    @stack('scripts')
</body>
</html>
