{{--
    One child's card. The board and Task 9's panel share this one rendering, so a
    chip or an action can never say two different things about the same child.

    Expects: $batch, $entry, $state (App\Services\BatchEntryState::for($entry)),
    and $joinCode - that child’s session code, handed in by whoever includes this
    (the board passes its own one-query map, the panel passes its $session), so
    the card never runs a query of its own.

    $compact (optional, default false) is the panel's rendering of the same
    card. The panel IS the child's own screen, so every door the board card
    offers would lead back to the page it is already on: the one action and the
    row that takes the child off the board are left off, the card fills its
    column instead of being a third of one, and the name is plain text rather
    than a link to here. What remains is what the panel came for - the chip, the
    words, the result link and the player. public/js/batch-board.js reads
    data-compact and holds to the same rule, so a poll cannot put back a button
    the server deliberately left out.

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
    $compact = $compact ?? false;

    $weakness = $entry->weaknessOutcome();
    $level = $entry->effectiveReadingLevel();
    $accuracy = $entry->effectiveAccuracy();
    $passage = $entry->material?->title ?? $batch->defaultMaterial?->title;
    $entryUrl = route('assessments.batch.entry', [$batch, $entry]);
    $childName = $entry->learner?->full_name ?? 'Unknown child';

    $signature = implode('|', [
        $state['chip'], $state['label'], $state['action'] ?? '', $state['result_url'] ?? '',
        // Crossing the staleness bound changes no chip and no label, only
        // whether this card offers a door - so it has to be in the signature or
        // the poll would skip the card and the door would never appear.
        $state['running'] ? 'running' : '',
        // The place in the line and the wait that follows from it. In the
        // signature because they are what changes most often: a card that
        // moved from "3rd in line" to "2nd in line" has the same chip, the
        // same tone and the same action, so without these the poll would read
        // the card as unchanged and the number would sit there being wrong.
        $state['position'] ?? '',
        $state['eta_minutes'] ?? '',
    ]);

@endphp

<div @class(['col-12', 'col-sm-6 col-lg-4' => ! $compact])>
    <div class="card h-100 border-0 shadow-sm bb-card position-relative"
         @if($compact) data-compact @endif
         data-entry="{{ $entry->id }}"
         data-entry-url="{{ $entryUrl }}"
         data-sig="{{ $signature }}">
        <div class="card-body d-flex flex-column gap-2">
            <div class="d-flex justify-content-between align-items-start gap-2">
                {{-- min-w-0 so a long name wraps inside the panel's wider card
                     instead of pushing the chip off the edge. --}}
                <h2 class="h6 mb-0 fw-bold text-break" style="min-width: 0;">
                    @if($compact)
                        {{ $childName }}
                    @else
                        <a class="stretched-link text-decoration-none text-body" href="{{ $entryUrl }}">{{ $childName }}</a>
                    @endif
                </h2>
                <span class="pp-pill pp-{{ $state['tone'] }} bb-chip" data-chip>
                    {{-- One class attribute, not two. Written as
                         class="spinner-border spinner-border-sm" … @class([...])
                         it rendered a second class attribute, and a duplicate
                         attribute is discarded - so the spinner span kept only
                         its first class list and spun on every card in every
                         state. --}}
                    <span @class(['spinner-border', 'spinner-border-sm', 'd-none' => $state['chip'] !== 'transcribing'])
                          role="status" aria-hidden="true" data-chip-spinner></span>
                    <span data-chip-text>{{ $state['chip'] }}</span>
                </span>
            </div>

            <p class="small text-muted mb-0" data-label>{{ $state['label'] }}</p>

            {{--
                A loading indication for a reading being transcribed, and
                deliberately an INDETERMINATE one.

                Nothing inside Whisper is observable from here, so there is no
                honest percentage to draw: a bar at 60% would be an invention,
                and a teacher would read it as a promise. This bar carries no
                width, no value and no aria-valuenow - it only says that work
                is under way. The words beside it (data-label) carry the facts
                that can be measured: how long this run has been going, and how
                long runs like it usually take.

                Toggled by class rather than rendered conditionally, so the
                poll can show and hide it in place without re-rendering the
                card - the same reason the chip's spinner is built this way.
            --}}
            {{-- @class owns the whole attribute: written as
                 class="progress bb-progress @class([...])" it rendered a second,
                 nested class="..." that the browser discards, so the bar showed
                 on every card in every state. --}}
            <div @class(['progress', 'bb-progress', 'd-none' => $state['chip'] !== 'transcribing'])
                 data-progress role="status" aria-label="Analysis in progress">
                <div class="progress-bar progress-bar-striped progress-bar-animated"></div>
            </div>

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

            {{--
                Every button this card offers, on one line.

                Conduct here and Remove used to be two stacked rows, which read
                as two separate decisions when they are the two things a teacher
                does with a waiting child. They sit beside each other now, and
                wrap onto a second line together when the card is too narrow for
                both.

                The two inner rows stay distinct elements, though, because the
                poll treats them differently: it rebuilds [data-actions] from
                JSON, which would wipe a form and its CSRF token, so the forms
                live in [data-manage] as a sibling - never nested inside - and
                the poll compares that row's value instead and asks the server
                to re-render the card when it has to change.
            --}}
            <div class="mt-auto d-flex flex-wrap align-items-center gap-2">
                <div class="d-flex flex-wrap gap-2 bb-act" data-actions>
                    @if($state['result_url'])
                        <a href="{{ $state['result_url'] }}" class="btn btn-sm btn-primary">
                            <i class="bi bi-clipboard-data me-1"></i> Check Result
                        </a>
                    @endif

                    {{-- The action always leads to the child's own panel, so on
                         that panel it is a button back to this page. --}}
                    @if($state['action'] && ! $compact)
                        <a href="{{ $entryUrl }}" class="btn btn-sm btn-outline-primary">{{ $state['action'] }}</a>
                    @endif
                </div>

                @unless($compact)
                    @include('assessments.batch._manage', ['batch' => $batch, 'entry' => $entry, 'state' => $state])
                @endunless
            </div>
        </div>
    </div>
</div>
