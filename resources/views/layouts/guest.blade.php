<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'BIGKAS-AI')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="{{ asset('css/app.css') }}" rel="stylesheet">
    @stack('styles')
</head>
<body>
    {{-- Simple navbar for guests --}}
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
        <div class="container">
            <a class="navbar-brand fw-bold text-primary" href="{{ url('/') }}">
                <i class="bi bi-book"></i> BIGKAS-AI
            </a>
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse"
                    data-bs-target="#guestNav" aria-controls="guestNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="guestNav">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link" href="#features">Features</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#teachers">For Teachers</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#parents">For Parents</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#students">For Students</a>
                    </li>
                </ul>
            </div>
            <div class="ms-auto">
                <a href="{{ route('login') }}" class="btn btn-outline-primary btn-sm me-2">Login</a>
                <a href="{{ route('register') }}" class="btn btn-primary btn-sm">Register</a>
            </div>
        </div>
    </nav>

    @yield('content')

    <footer class="bg-dark text-white py-4 mt-5">
        <div class="container text-center">
            <div class="mb-3">
                <a href="{{ route('privacy') }}" class="text-white text-decoration-none mx-2">Privacy Policy</a>
            </div>
            <p class="mb-0">&copy; 2026 BIGKAS-AI. All rights reserved.</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    @stack('scripts')
</body>
</html>
