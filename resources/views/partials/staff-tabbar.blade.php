{{-- Mobile bottom tab bar (hidden at 992px and up) --}}
<nav class="pp-tabbar d-lg-none" aria-label="Main">
    @foreach($navTabs as $tab)
        <a href="{{ route($tab['route']) }}" class="pp-tab {{ request()->routeIs(...$tab['active']) ? 'active' : '' }}">
            <i class="bi {{ $tab['icon'] }}"></i><span>{{ $tab['label'] }}</span>
            @if($tab['badge'] === 'msgs' && $navUnreadMsgs > 0)<b class="pp-tab-badge">{{ $navUnreadMsgs > 9 ? '9+' : $navUnreadMsgs }}</b>@endif
        </a>
    @endforeach

    <button type="button" class="pp-tab {{ collect($navMore)->contains(fn ($i) => request()->routeIs(...$i['active'])) ? 'active' : '' }}"
            data-bs-toggle="offcanvas" data-bs-target="#ppMore" aria-controls="ppMore">
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
            @foreach($navMore as $item)
                <a href="{{ route($item['route']) }}"><i class="bi {{ $item['icon'] }}"></i>{{ $item['label'] }}</a>
            @endforeach
            <a href="{{ route('notifications.index') }}"><i class="bi bi-bell"></i>Notifications
                @if($navUnreadNotifs > 0)<b class="pp-tab-badge">{{ $navUnreadNotifs > 9 ? '9+' : $navUnreadNotifs }}</b>@endif
            </a>
            <a href="{{ route('profile.show') }}"><i class="bi bi-person"></i>My profile</a>
        </div>

        <form method="POST" action="{{ route('logout') }}" class="mt-3">@csrf
            <button type="submit" class="btn btn-outline-danger w-100"><i class="bi bi-box-arrow-right me-1"></i> Log out</button>
        </form>
    </div>
</div>
