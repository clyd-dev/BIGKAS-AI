{{-- Parent top bar (all screen sizes) --}}
<header class="pp-topbar">
    <div class="d-flex align-items-center gap-1">
        {{-- Hamburger: opens the slide-out menu on phones/tablets, hides/shows the sidebar on desktop --}}
        <button type="button" class="pp-icon-btn" id="ppMenuBtn" aria-label="Open menu" aria-controls="ppDrawer ppSidebar">
            <i class="bi bi-list"></i>
        </button>
        <a class="pp-brand" href="{{ route('parent.dashboard') }}">
            <i class="bi bi-book-half"></i><span>BIGKAS-AI</span>
        </a>
    </div>

    <div class="pp-topbar-right">
        {{-- Child switcher, only when there is more than one child --}}
        @if($navChildren->count() > 1)
            <div class="dropdown">
                <button class="pp-chip dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-person-circle"></i>
                    <span class="pp-chip-name">{{ $navChild?->first_name }}</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end pp-menu">
                    <li><h6 class="dropdown-header">Whose progress?</h6></li>
                    @foreach($navChildren as $c)
                        <li>
                            <a class="dropdown-item {{ $navChild?->id === $c->id ? 'active' : '' }}"
                               href="{{ route('parent.children.profile', $c) }}">
                                {{ $c->first_name }} <span class="small opacity-75">&middot; Grade {{ $c->grade_level }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Notifications: a plain link, no popup to overflow a small screen --}}
        <a href="{{ route('notifications.index') }}" class="pp-icon-btn" aria-label="Notifications{{ $navUnreadNotifs ? ', '.$navUnreadNotifs.' new' : '' }}">
            <i class="bi bi-bell"></i>
            @if($navUnreadNotifs > 0)<span class="pp-dot">{{ $navUnreadNotifs > 9 ? '9+' : $navUnreadNotifs }}</span>@endif
        </a>

        {{-- Account menu: desktop only (phones use the "More" tab) --}}
        <div class="dropdown d-none d-lg-block">
            <button class="pp-chip dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-person"></i>
                <span class="pp-chip-name">{{ Str::before(Auth::user()->name, ' ') }}</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end pp-menu">
                <li><a class="dropdown-item" href="{{ route('profile.show') }}"><i class="bi bi-person me-2"></i>My profile</a></li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form method="POST" action="{{ route('logout') }}">@csrf
                        <button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i>Log out</button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</header>
