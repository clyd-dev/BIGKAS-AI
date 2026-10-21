{{--
    One child's card. The board and Task 9's panel share this one rendering, so a
    chip or an action can never say two different things about the same child.

    Expects: $batch, $entry, $state (App\Services\BatchEntryState::for($entry)),
    and $joinCode - that child’s session code, handed in by whoever includes this
    (the board passes its own one-query map, the panel passes its $session), so
    the card never runs a query of its own.

    The whole card is clickable through a stretched link, so the buttons inside
    it sit above that link rather than nested inside an anchor.

    The data- hooks are what lets public/js/batch-board.js patch one card in
    place instead of reloading the page: a reload would stop audio the teacher is
    listening to, which is the one thing the card must keep offering. Each hook
    is named for what it holds, and `data-sig` is the state the card is currently
    showing, so the poll can tell at a glance that nothing changed and touch
    nothing at all.
--}}
@php
    $weakness = $entry->weaknessOutcome();
    $level = $entry->effectiveReadingLevel();
    $accuracy = $entry->effectiveAccuracy();
    $passage = $entry->material?->title ?? $batch->defaultMaterial?->title;
    $entryUrl = route('assessments.batch.entry', [$batch, $entry]);
    $childName = $entry->learner?->full_name ?? 'Unknown child';
    $signature = implode('|', [$state['chip'], $state['label'], $state['action'] ?? '', $state['result_url'] ?? '']);
@endphp

<div class="col-12 col-sm-6 col-lg-4">
    <div class="card h-100 border-0 shadow-sm bb-card position-relative"
         data-entry="{{ $entry->id }}"
         data-entry-url="{{ $entryUrl }}"
         data-sig="{{ $signature }}">
        <div class="card-body d-flex flex-column gap-2">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <h2 class="h6 mb-0 fw-bold">
                    <a class="stretched-link text-decoration-none text-body" href="{{ $entryUrl }}">{{ $childName }}</a>
                </h2>
                <span class="pp-pill pp-{{ $state['tone'] }} bb-chip" data-chip>
                    <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"
                          data-chip-spinner @class(['d-none' => $state['chip'] !== 'transcribing'])></span>
                    <span data-chip-text>{{ $state['chip'] }}</span>
                </span>
            </div>

            <p class="small text-muted mb-0" data-label>{{ $state['label'] }}</p>

            @if($state['chip'] === 'done')
                <div data-done>
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
                </div>
            @endif

            @if($state['chip'] === 'waiting')
                {{-- default_material_id is nullable, so a batch may have per-entry
                     passages and no default. Say which passage, or say there is
                     none to name - never leave the slot blank. --}}
                <p class="small text-muted mb-0">
                    @if($passage)
                        Passage: {{ $passage }}
                    @else
                        No passage chosen yet — pick one when you conduct this reading.
                    @endif
                </p>
                {{-- The child's own code, for the teacher's records. It is not a
                     way in: no screen in this application accepts a code. --}}
                @if($joinCode ?? null)
                    <p class="small mb-0" data-join-code>
                        <span class="text-muted">Session code (for your records):</span>
                        <span class="fw-bold font-monospace text-primary">{{ $joinCode }}</span>
                    </p>
                @endif
            @endif

            @if($state['playable'])
                <audio class="w-100 bb-act" controls preload="none" src="{{ $entry->getAudioUrl() }}"
                       aria-label="Recording for {{ $childName }}"></audio>
            @endif

            <div class="mt-auto d-flex flex-wrap gap-2 bb-act" data-actions>
                @if($state['result_url'])
                    <a href="{{ $state['result_url'] }}" class="btn btn-sm btn-primary">
                        <i class="bi bi-clipboard-data me-1"></i> Check Result
                    </a>
                @endif

                @if($state['action'])
                    <a href="{{ $entryUrl }}" class="btn btn-sm btn-outline-primary">{{ $state['action'] }}</a>
                @endif
            </div>
        </div>
    </div>
</div>
