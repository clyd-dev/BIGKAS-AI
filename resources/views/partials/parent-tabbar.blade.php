{{-- Mobile bottom tab bar (hidden at 992px and up) --}}
<nav class="pp-tabbar d-lg-none" aria-label="Main">
    <a href="{{ route('parent.dashboard') }}" class="pp-tab {{ request()->routeIs('parent.dashboard') ? 'active' : '' }}">
        <i class="bi bi-house-heart"></i><span>Home</span>
    </a>

    @if($navChild)
        <a href="{{ route('parent.children.profile', $navChild) }}"
           class="pp-tab {{ request()->routeIs('parent.children.profile', 'parent.children.assessment*', 'parent.children.practice') ? 'active' : '' }}">
            <i class="bi bi-emoji-smile"></i><span>{{ Str::limit($navChild->first_name, 9, '…') }}</span>
        </a>
        <a href="{{ route('parent.children.interventions', $navChild) }}"
           class="pp-tab {{ request()->routeIs('parent.children.interventions') ? 'active' : '' }}">
            <i class="bi bi-clipboard-heart"></i><span>Activities</span>
        </a>
    @endif

    <a href="{{ route('parent.messages.index') }}" class="pp-tab {{ request()->routeIs('parent.messages.*') ? 'active' : '' }}">
        <i class="bi bi-envelope"></i><span>Messages</span>
        @if($navUnreadMsgs > 0)<b class="pp-tab-badge">{{ $navUnreadMsgs > 9 ? '9+' : $navUnreadMsgs }}</b>@endif
    </a>

    <button type="button" class="pp-tab" data-bs-toggle="offcanvas" data-bs-target="#ppMore" aria-controls="ppMore">
        <i class="bi bi-grid"></i><span>More</span>
    </button>
</nav>

{{-- "More" sheet --}}
<div class="offcanvas offcanvas-bottom pp-sheet d-lg-none" tabindex="-1" id="ppMore" aria-labelledby="ppMoreTitle">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="ppMoreTitle">More</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body pt-0">
        <div class="pp-sheet-grid">
            <a href="{{ route('reports.index') }}"><i class="bi bi-bar-chart"></i>Reports</a>
            @if($navChild)
                <a href="{{ route('parent.children.assessments', $navChild) }}"><i class="bi bi-clipboard-check"></i>Reading checks</a>
                <a href="{{ route('parent.children.practice', $navChild) }}"><i class="bi bi-controller"></i>Practice history</a>
            @endif
            <a href="{{ route('notifications.index') }}"><i class="bi bi-bell"></i>Notifications
                @if($navUnreadNotifs > 0)<b class="pp-tab-badge">{{ $navUnreadNotifs > 9 ? '9+' : $navUnreadNotifs }}</b>@endif
            </a>
            <a href="{{ route('profile.show') }}"><i class="bi bi-person"></i>My profile</a>
        </div>

        @if($navChildren->count() > 1)
            <div class="pp-side-label mt-3">Switch child</div>
            <div class="d-flex flex-wrap gap-2 mb-3">
                @foreach($navChildren as $c)
                    <a href="{{ route('parent.children.profile', $c) }}"
                       class="pp-chip {{ $navChild?->id === $c->id ? 'pp-chip-on' : '' }}">{{ $c->first_name }}</a>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('logout') }}" class="mt-2">@csrf
            <button type="submit" class="btn btn-outline-danger w-100"><i class="bi bi-box-arrow-right me-1"></i> Log out</button>
        </form>
    </div>
</div>
