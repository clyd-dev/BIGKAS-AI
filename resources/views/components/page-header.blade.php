{{--
    The one page header for every parent, teacher and admin page.

    The back link goes above the title (the way a phone app places it) and is
    labelled with where it goes, so it never competes for room with the page's
    action buttons. Styles live in public/css/parent-shell.css, which both
    layouts load.

    <x-page-header :title="$learner->full_name" icon="bi-person"
                   :back="route('learners.index')" back-label="Learners">
        <x-slot:actions>
            <a href="..." class="btn btn-primary btn-sm">New assessment</a>
        </x-slot:actions>
    </x-page-header>
--}}
@props([
    'title',
    'icon' => null,
    'subtitle' => null,
    'back' => null,
    'backLabel' => 'Back',
])

<div class="pg-head">
    @if($back)
        <a href="{{ $back }}" class="pg-back">
            <i class="bi bi-arrow-left"></i><span>{{ $backLabel }}</span>
        </a>
    @endif

    <div class="pg-head-row">
        <div class="pg-head-text">
            <h1 class="pg-title">
                @if($icon)<i class="bi {{ $icon }}"></i>@endif
                <span>{{ $title }}</span>
                @if(isset($badge)){{ $badge }}@endif
            </h1>
            @if($subtitle)<div class="pg-sub">{{ $subtitle }}</div>@endif
            @isset($meta){{ $meta }}@endisset
        </div>

        @if(isset($actions) && trim($actions->toHtml()) !== '')
            <div class="pg-actions">{{ $actions }}</div>
        @endif
    </div>
</div>
