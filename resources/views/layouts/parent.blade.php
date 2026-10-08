<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#1a73e8">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="BIGKAS">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="apple-touch-icon" href="{{ asset('icons/icon-192.png') }}">
    <title>@yield('title', 'BIGKAS') - BIGKAS-AI</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="{{ asset('css/parent.css') }}" rel="stylesheet">
    <link href="{{ asset('css/parent-shell.css') }}" rel="stylesheet">
    @stack('styles')
</head>
<body class="pp-body">
    <a href="#pp-main" class="visually-hidden-focusable pp-skip">Skip to content</a>

    @include('partials.parent-nav')

    <div class="pp-shell">
        @include('partials.parent-sidebar')

        <main id="pp-main" class="pp-main">
            <div class="pp-container">
                @include('partials.alerts')
                @yield('content')
            </div>
        </main>
    </div>

    @include('partials.parent-tabbar')

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    {{-- Heavy libraries (e.g. Chart.js) are pushed here by the pages that need them --}}
    <script>
        // Hamburger: slide-out drawer below 992px, collapsible sidebar above it.
        (function () {
            var btn = document.getElementById('ppMenuBtn');
            var drawerEl = document.getElementById('ppDrawer');
            if (!btn || !drawerEl) return;
            var KEY = 'ppSidebarHidden';
            var desktop = window.matchMedia('(min-width: 992px)');

            function applySaved() {
                var hidden = false;
                try { hidden = localStorage.getItem(KEY) === '1'; } catch (e) {}
                document.body.classList.toggle('pp-sidebar-hidden', desktop.matches && hidden);
            }
            btn.addEventListener('click', function () {
                if (desktop.matches) {
                    var hide = !document.body.classList.contains('pp-sidebar-hidden');
                    document.body.classList.toggle('pp-sidebar-hidden', hide);
                    try { localStorage.setItem(KEY, hide ? '1' : '0'); } catch (e) {}
                } else {
                    bootstrap.Offcanvas.getOrCreateInstance(drawerEl).toggle();
                }
            });
            // Close the drawer after choosing a link
            drawerEl.addEventListener('click', function (e) {
                if (e.target.closest('a')) bootstrap.Offcanvas.getOrCreateInstance(drawerEl).hide();
            });
            // Crossing the breakpoint (rotating a tablet): close the drawer, restore the sidebar choice
            desktop.addEventListener('change', function () {
                bootstrap.Offcanvas.getOrCreateInstance(drawerEl).hide();
                applySaved();
            });
            applySaved();
        })();
    </script>
    @stack('vendor')
    @stack('scripts')
</body>
</html>
