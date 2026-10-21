{{--
    One child's card. The board and Task 9's panel share this one rendering, so a
    chip or an action can never say two different things about the same child.

    Expects: $batch, $entry, $state (App\Services\BatchEntryState::for($entry)).

    The whole card is clickable through a stretched link, so the buttons inside
    it sit above that link rather than nested inside an anchor.
--}}
@php
    $weakness = $entry->weaknessOutcome();
    $level = $entry->effectiveReadingLevel();
    $accuracy = $entry->effectiveAccuracy();
@endphp

<div class="col-12 col-sm-6 col-lg-4">
    <div class="card h-100 border-0 shadow-sm bb-card position-relative" data-entry="{{ $entry->id }}">
        <div class="card-body d-flex flex-column gap-2">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <h2 class="h6 mb-0 fw-bold">
                    <a class="stretched-link text-decoration-none text-body"
                       href="{{ route('assessments.batch.entry', [$batch, $entry]) }}">
                        {{ $entry->learner?->full_name ?? 'Unknown child' }}
                    </a>
                </h2>
                <span class="pp-pill pp-{{ $state['tone'] }} bb-chip" data-chip>
                    @if($state['chip'] === 'transcribing')
                        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                    @endif
                    {{ $state['chip'] }}
                </span>
            </div>

            <p class="small text-muted mb-0" data-label>{{ $state['label'] }}</p>

            @if($state['chip'] === 'done')
                @if($entry->isInvalidated())
                    <p class="small mb-0 text-muted">This reading was marked invalid, so it shows no numbers.</p>
                @else
                    <p class="small mb-0">
                        @if($level)<span class="fw-semibold text-capitalize">{{ $level }}</span>@endif
                        @if($accuracy !== null)<span class="text-muted">· {{ round($accuracy) }}% accuracy</span>@endif
                    </p>
                @endif
                <p class="small mb-0">
                    @if($weakness['usable'] && $weakness['name'])
                        <span class="text-muted">Needs work on</span> {{ $weakness['name'] }}
                    @else
                        <span class="text-muted">No weakness label for this reading.</span>
                    @endif
                </p>
            @endif

            @if($state['chip'] === 'waiting' && $batch->defaultMaterial)
                <p class="small text-muted mb-0">Passage: {{ $entry->material?->title ?? $batch->defaultMaterial->title }}</p>
            @endif

            @if($state['playable'])
                <audio class="w-100 bb-act" controls preload="none" src="{{ $entry->getAudioUrl() }}"
                       aria-label="Recording for {{ $entry->learner?->full_name ?? 'this child' }}"></audio>
            @endif

            <div class="mt-auto d-flex flex-wrap gap-2 bb-act">
                @if($state['result_url'])
                    <a href="{{ $state['result_url'] }}" class="btn btn-sm btn-primary">
                        <i class="bi bi-clipboard-data me-1"></i> Check Result
                    </a>
                @endif

                @if($state['action'])
                    <a href="{{ route('assessments.batch.entry', [$batch, $entry]) }}" class="btn btn-sm btn-outline-primary">
                        {{ $state['action'] }}
                    </a>
                @endif
            </div>
        </div>
    </div>
</div>
