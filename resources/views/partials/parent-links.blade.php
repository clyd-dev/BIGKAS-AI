{{-- Parent navigation links, shared by the desktop sidebar and the hamburger menu --}}
<a href="{{ route('parent.dashboard') }}" class="pp-side-link {{ request()->routeIs('parent.dashboard') ? 'active' : '' }}">
    <i class="bi bi-house-heart"></i> Home
</a>

@if($navChildren->isNotEmpty())
    <div class="pp-side-label">My children</div>
    @foreach($navChildren as $c)
        <a href="{{ route('parent.children.profile', $c) }}"
           class="pp-side-link ps-4 {{ request()->is('parent/children/'.$c->id.'*') ? 'active' : '' }}">
            <i class="bi bi-person"></i> {{ $c->first_name }}
        </a>
    @endforeach
@endif

@if($navChild)
    <div class="pp-side-label">{{ $navChild->first_name }}</div>
    <a href="{{ route('parent.children.assessments', $navChild) }}" class="pp-side-link {{ request()->routeIs('parent.children.assessment*') ? 'active' : '' }}">
        <i class="bi bi-clipboard-check"></i> Reading checks
    </a>
    <a href="{{ route('parent.children.interventions', $navChild) }}" class="pp-side-link {{ request()->routeIs('parent.children.interventions') ? 'active' : '' }}">
        <i class="bi bi-house-heart"></i> Home activities
    </a>
    <a href="{{ route('parent.children.practice', $navChild) }}" class="pp-side-link {{ request()->routeIs('parent.children.practice') ? 'active' : '' }}">
        <i class="bi bi-controller"></i> Practice history
    </a>
@endif

<div class="pp-side-label">More</div>
<a href="{{ route('reports.index') }}" class="pp-side-link {{ request()->routeIs('reports.*') ? 'active' : '' }}">
    <i class="bi bi-bar-chart"></i> Reports
</a>
<a href="{{ route('parent.messages.index') }}" class="pp-side-link {{ request()->routeIs('parent.messages.*') ? 'active' : '' }}">
    <i class="bi bi-envelope"></i> Messages
    @if($navUnreadMsgs > 0)<span class="badge bg-danger rounded-pill ms-auto">{{ $navUnreadMsgs }}</span>@endif
</a>
<a href="{{ route('notifications.index') }}" class="pp-side-link {{ request()->routeIs('notifications.*') ? 'active' : '' }}">
    <i class="bi bi-bell"></i> Notifications
    @if($navUnreadNotifs > 0)<span class="badge bg-danger rounded-pill ms-auto">{{ $navUnreadNotifs }}</span>@endif
</a>
<a href="{{ route('profile.show') }}" class="pp-side-link {{ request()->routeIs('profile.*') ? 'active' : '' }}">
    <i class="bi bi-person-gear"></i> My profile
</a>
