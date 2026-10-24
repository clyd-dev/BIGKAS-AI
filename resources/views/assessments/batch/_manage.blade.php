{{--
    How a child leaves the board: the one form that fits the state they are in.

    Shared by the board's card and by the foot of the panel's Conduct here card,
    so a teacher is offered the same door with the same words and the same
    consequences wherever she is standing - and so neither place can drift into
    offering something the other refuses.

    Expects: $batch, $entry (with comprehensionAnswers counted) and $state.

    Rendered by the server, always: these are forms with CSRF tokens in them, so
    public/js/batch-board.js never builds one. What it does instead is read
    [data-manage] - the state this row was built for - and ask the server for
    fresh markup when the state has moved to a different door. An un-send form
    left behind would offer to stop a Whisper run that is already finished.
--}}
@php
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

@if($state['chip'] === 'transcribing' && $state['running'])
{{-- w-100 because this is a sentence, not a button: on the board's card
     it takes the whole line rather than trying to sit beside Check Result. --}}
<p class="small text-muted mb-0 fst-italic w-100" data-manage="">
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
