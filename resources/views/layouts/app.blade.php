<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'BIGKAS') - BIGKAS-AI</title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <!-- App CSS -->
    <link href="{{ asset('css/app.css') }}" rel="stylesheet">
    @stack('styles')
</head>
<body class="app-shell">
    {{-- Navbar --}}
    @include('partials.navbar')

    <div class="d-flex" id="wrapper">
        {{-- Sidebar --}}
        @include('partials.sidebar')

        {{-- Main Content --}}
        <div id="page-content-wrapper" class="flex-grow-1">
            <div class="container-fluid py-4 px-4">
                {{-- Flash Messages --}}
                @include('partials.alerts')

                @yield('content')
            </div>
        </div>
    </div>

    <div id="sidebarBackdrop" class="sidebar-backdrop"></div>

    <script>
        // Hamburger: slide the sidebar in over the page on small screens
        (function () {
            var btn = document.getElementById('sidebarToggle');
            var bd = document.getElementById('sidebarBackdrop');
            var side = document.getElementById('sidebar-wrapper');
            if (!btn || !side) return;
            function set(open) {
                document.body.classList.toggle('sidebar-open', open);
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            }
            btn.addEventListener('click', function () { set(!document.body.classList.contains('sidebar-open')); });
            bd.addEventListener('click', function () { set(false); });
            side.addEventListener('click', function (e) { if (e.target.closest('a')) set(false); });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape') set(false); });
            window.matchMedia('(min-width: 992px)').addEventListener('change', function () { set(false); });
        })();
    </script>

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- App JS -->
    <script src="{{ asset('js/app.js') }}"></script>
    @stack('scripts')
</body>
</html>
