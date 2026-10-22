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

    /*
     * The same name again, for the inline confirm() handlers below.
     *
     * Blade's e() turns O'Brien into O&#039;Brien, and the browser HTML-decodes
     * an attribute *before* parsing it as JavaScript - so the apostrophe lands
     * back inside the JS string literal, the handler is a syntax error, it never
     * compiles, its `return false` never happens and the form submits with no
     * dialog at all. On the Remove button that means a child's entry and their
     * recording deleted on one tap, silently, because of their surname.
     * O'Brien and D'Cruz are ordinary names on a Philippine class roll.
     *
     * addslashes() first, e() after, which is the repo's existing pattern
     * (admin/users.blade.php, learners/_student_portal.blade.php): the backslash
     * survives the entity round-trip and the string closes where it should.
     */
    $confirmName = addslashes($childName);

    /*
     * Deleting a waiting or recorded entry cascade-deletes any comprehension
     * answers the teacher had already typed for it, so the confirmation names
     * them rather than letting them go unmentioned. The count is handed in by
     * the board and the panel - this card never queries (R39).
     */
    $answerCount = (int) ($entry->comprehension_answers_count ?? 0);
    $answerLoss = $answerCount === 0
        ? ''
        : ' The ' . $answerCount . ' comprehension ' . ($answerCount === 1 ? 'answer' : 'answers')
            . ' already entered for them ' . ($answerCount === 1 ? 'goes' : 'go') . ' too.';
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

    /*
     * Which way this child leaves the board, by state - and `transcribing`
     * leaves it no way at all, because a running Whisper process cannot be
     * aborted honestly and a button that did not do what it claimed would be
     * worse than no button.
     *
     *   remove  the row, its session and its unsent audio are deleted; nothing
     *           has been measured
     *   unsend  the reading comes back out of the lane, audio kept
     *   detach  batch_id = null and nothing else: the result, the verdict and
     *           the comprehension answers all stay on the child's record
     *
     * `queued` offers no remove on purpose: the teacher cancels the analysis
     * first, so taking a child off the board is never also a decision about
     * lane time - unless the reading has already been measured, in which case
     * the controller detaches it, because a result that the model could not
     * classify also rests at `queued` and must still be able to leave.
     *
     * `transcribing` is the one state with no door - while something is really
     * running. Past the lane's own staleness bound ($state['running'] goes
     * false) the engine has died or the worker was killed, nothing is listening
     * to the recording any more, and a card the teacher cannot clear would be
     * the dishonest answer. Then it offers Cancel analysis like any other sent
     * reading.
     *
     * Every one of these is re-checked in the controller at write time - this
     * board may be a minute old.
     */
    $manage = match ($state['chip']) {
        'waiting', 'recorded' => 'remove',
        'queued' => 'unsend',
        'needs verdict', 'done', 'failed' => 'detach',
        'transcribing' => $state['running'] ? '' : 'unsend',
        default => '',
    };
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
            <div class="progress bb-progress @class(['d-none' => $state['chip'] !== 'transcribing'])"
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

            {{--
                Kept out of [data-actions] deliberately: the poll rebuilds that
                row's HTML from JSON, which would wipe a form and its CSRF
                token. data-manage is what the poll compares instead - when the
                state calls for a different one, the card is re-rendered by the
                server rather than patched here.
            --}}
            @if($state['chip'] === 'transcribing' && $state['running'])
                <p class="small text-muted mb-0 fst-italic" data-manage="">
                    Being analysed now — this one cannot be stopped. The result appears on this card when it is done.
                </p>
            @elseif($manage !== '')
                <div class="d-flex flex-wrap gap-2 bb-act" data-manage="{{ $manage }}">
                    @if($manage === 'unsend')
                        <form method="POST" action="{{ route('assessments.batch.unsend', [$batch, $entry]) }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-arrow-counterclockwise me-1"></i> Cancel analysis
                            </button>
                        </form>
                    @elseif($manage === 'detach')
                        {{-- Nothing is deleted here: this only takes the child off
                             the board. The reading, its result, your verdict and
                             the comprehension answers stay on their history. --}}
                        <form method="POST" action="{{ route('assessments.batch.entry.remove', [$batch, $entry]) }}"
                              onsubmit="return confirm('Take {{ $confirmName }} off this board? Their reading, its result and any verdict you had given are all kept on their own history — only the batch link is removed.')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-box-arrow-right me-1"></i> Remove from batch
                            </button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('assessments.batch.entry.remove', [$batch, $entry]) }}"
                              onsubmit="return confirm('{{ $state['chip'] === 'recorded' ? 'Remove ' . $confirmName . ' and delete the recording that has not been sent? Nothing has been measured yet, so nothing is lost from their record.' . $answerLoss : 'Remove ' . $confirmName . ' from this batch? Nothing has been recorded for them yet.' . $answerLoss }}')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="bi bi-x-lg me-1"></i> Remove
                            </button>
                        </form>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
