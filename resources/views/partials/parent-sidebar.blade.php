{{-- Desktop sidebar: always shown at 992px and up, can be hidden with the hamburger --}}
<nav class="pp-sidebar d-none d-lg-flex" id="ppSidebar" aria-label="Main">
    @include('partials.parent-links')
</nav>

{{-- Hamburger menu (phones and tablets): slides in over the page, so it never blocks the content --}}
<div class="offcanvas offcanvas-start pp-drawer" tabindex="-1" id="ppDrawer" aria-labelledby="ppDrawerTitle">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="ppDrawerTitle"><i class="bi bi-book-half me-2"></i>BIGKAS-AI</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close menu"></button>
    </div>
    <div class="offcanvas-body d-flex flex-column p-2">
        @include('partials.parent-links')
        <form method="POST" action="{{ route('logout') }}" class="mt-auto p-2">@csrf
            <button type="submit" class="btn btn-outline-danger w-100"><i class="bi bi-box-arrow-right me-1"></i> Log out</button>
        </form>
    </div>
</div>
