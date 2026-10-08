{{-- Admin / teacher links, shared by the desktop sidebar and the hamburger menu --}}
@foreach($navSections as $section)
    @if($section['label'])<div class="pp-side-label">{{ $section['label'] }}</div>@endif
    @foreach($section['items'] as $item)
        <a href="{{ route($item['route']) }}" class="pp-side-link {{ request()->routeIs(...$item['active']) ? 'active' : '' }}">
            <i class="bi {{ $item['icon'] }}"></i> {{ $item['label'] }}
            @if($item['badge'] === 'msgs' && $navUnreadMsgs > 0)<span class="badge bg-danger rounded-pill ms-auto">{{ $navUnreadMsgs }}</span>@endif
        </a>
    @endforeach
@endforeach
