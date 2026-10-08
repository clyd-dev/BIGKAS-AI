@if ($paginator->hasPages())
    <nav class="bigkas-pager" role="navigation" aria-label="Pagination">
        <ul class="bigkas-pager__list">
            {{-- Previous --}}
            <li>
                @if ($paginator->onFirstPage())
                    <span class="bigkas-pager__btn bigkas-pager__btn--wide is-disabled" aria-disabled="true">
                        <i class="bi bi-chevron-left"></i><span class="d-none d-sm-inline ms-1">Previous</span>
                    </span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="bigkas-pager__btn bigkas-pager__btn--wide">
                        <i class="bi bi-chevron-left"></i><span class="d-none d-sm-inline ms-1">Previous</span>
                    </a>
                @endif
            </li>

            {{-- Page numbers --}}
            {{-- $elements is absent when this view backs a simple paginator --}}
            @foreach ($elements ?? [] as $element)
                @if (is_string($element))
                    <li class="d-none d-sm-block"><span class="bigkas-pager__gap">{{ $element }}</span></li>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        <li class="{{ $page == $paginator->currentPage() ? '' : 'd-none d-sm-block' }}">
                            @if ($page == $paginator->currentPage())
                                <span class="bigkas-pager__btn is-active" aria-current="page">{{ $page }}</span>
                            @else
                                <a href="{{ $url }}" class="bigkas-pager__btn" aria-label="Go to page {{ $page }}">{{ $page }}</a>
                            @endif
                        </li>
                    @endforeach
                @endif
            @endforeach

            {{-- Next --}}
            <li>
                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="bigkas-pager__btn bigkas-pager__btn--wide">
                        <span class="d-none d-sm-inline me-1">Next</span><i class="bi bi-chevron-right"></i>
                    </a>
                @else
                    <span class="bigkas-pager__btn bigkas-pager__btn--wide is-disabled" aria-disabled="true">
                        <span class="d-none d-sm-inline me-1">Next</span><i class="bi bi-chevron-right"></i>
                    </span>
                @endif
            </li>
        </ul>
    </nav>
@endif
