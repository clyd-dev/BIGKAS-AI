{{--
    One child's panel: where the teacher conducts this reading, listens to it
    and sends it.

    Either way of reading still works and neither is chosen at setup: the
    teacher conducts the reading here, or the child reads it on their own device
    after signing in to the student portal with their PIN - there is no code for
    them to type, the reading is simply waiting on their dashboard, and the
    recording lands on this panel whichever way it was made. That needs no
    screen of its own, so this page is only the one it used to be half of.

    The chip, the label and the result link come from the board's own
    _card.blade.php, included here rather than written again, so the panel can
    never say something different about a child than the board does. It is
    included compact: the buttons that lead back to this page, and the row that
    takes a child off the board, belong to the board. The card is kept up to
    date by public/js/batch-board.js - the same poll, patching this one card in
    place - so sending or recording here never reloads the page and never stops
    a recording the teacher is listening to.

    Expects: $batch, $entry, $state, $lane, $material, $questions, $answers, $session.
    $state already carries this reading's place in the scoring lane and its wait,
    computed once in the controller from $lane - the card never queries (R39).
--}}
@extends('layouts.app')

@php
    $childName = $entry->learner?->full_name ?? 'Unknown child';
    $firstName = $entry->learner?->first_name ?? 'this child';
@endphp

@section('title', $childName)

@push('styles')
    {{-- The good / ok / help / none tone palette the chips wear, as on the board. --}}
    <link href="{{ asset('css/parent.css') }}" rel="stylesheet">
    <link href="{{ asset('css/batch-board.css') }}" rel="stylesheet">
@endpush

@section('content')
    <x-page-header :title="$childName" icon="bi-mic"
                   :back="route('assessments.batch.board', $batch)"
                   :back-label="$batch->title ?: 'Group Assessment'"
                   :subtitle="'Record this reading here, or let ' . $firstName . ' read it on their own device after signing in with their PIN.'" />

    @if($lane['stalled_minutes'])
        {{--
            The same observation the board makes, for a teacher who came
            straight to a child's panel. Observed, never diagnosed: this page
            cannot see the server's scheduler, only that nothing has been
            scored for far longer than a run takes.
        --}}
        <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
            <i class="bi bi-exclamation-triangle mt-1"></i>
            <div>
                <div class="fw-semibold">
                    Nothing has been scored for {{ $lane['stalled_minutes'] }} minutes, although readings are waiting.
                </div>
                <div class="small">
                    The analysis scheduler may not be running — ask whoever looks after the server to check it.
                    Nothing is lost in the meantime: this recording stays in line.
                </div>
            </div>
        </div>
    @endif

    <div class="row g-3">
        <div class="col-lg-5">
            {{-- What the board says about this child, rendered by the board's own
                 card - compact, so it fills this column instead of sitting in a
                 third of it. --}}
            <div class="row g-3 mb-3">
                @include('assessments.batch._card', ['batch' => $batch, 'entry' => $entry, 'state' => $state, 'joinCode' => $session?->session_code, 'compact' => true])
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="bi bi-mic-fill text-danger me-1"></i> Conduct here</h2>
                </div>
                @if($entry->result)
                    {{--
                        This reading has been analysed. Recording over it is
                        refused by uploadAudio(), because the card could never
                        read `recorded` again with a result in place - it would
                        go on showing numbers belonging to a recording that is no
                        longer on disk. So the recorder is not offered at all
                        rather than offered and then refused after the child has
                        already read.
                    --}}
                    <div class="card-body">
                        <p class="small mb-2">
                            {{ $firstName }}'s reading has already been analysed, so it cannot be recorded over —
                            the result on the card belongs to the recording that is saved.
                        </p>
                        <p class="small text-muted mb-0">
                            To score that recording again, open the result. To have {{ $firstName }} read a fresh
                            passage, start a new reading for them rather than replacing this one.
                        </p>
                    </div>
                @else
                <div class="card-body" data-recorder>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <button type="button" class="btn btn-danger rounded-pill px-4" data-record-start>
                            <i class="bi bi-mic-fill me-1"></i> Start reading
                        </button>
                        <button type="button" class="btn btn-secondary rounded-pill px-4 d-none" data-record-stop>
                            <i class="bi bi-stop-fill me-1"></i> Stop
                        </button>
                        <span class="fw-bold text-danger" data-record-timer>00:00</span>
                    </div>

                    {{-- The just-recorded take, for an immediate listen. The player
                         for a recording already on the server is the card's own,
                         above, so this panel never shows two of the same thing. --}}
                    <div class="mt-3" data-record-preview></div>

                    <p class="small mb-0 mt-3" data-recorder-message role="status" aria-live="polite"></p>

                    <div class="mt-3 d-flex flex-wrap gap-2">
                        {{-- Offered on exactly the terms the card offers it, which
                             is the Send gate: a comprehension reading with no
                             answers yet has no action, so there is nothing to
                             press until the answers are in. --}}
                        <button type="button" class="btn btn-primary" data-send
                                @disabled($state['action'] !== 'Send for analysis')>
                            <i class="bi bi-send me-1"></i> Send for analysis
                        </button>
                    </div>
                </div>
                @endif
            </div>
        </div>

        <div class="col-lg-7">
            {{-- The passage comes first: the questions below it ask about this text,
                 and a child should not be asked what happened in a story that
                 is further down the page. mb-3 only when something follows. --}}
            <div @class(['card', 'border-0', 'shadow-sm', 'mb-3' => $questions->isNotEmpty()])>
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h2 class="h6 mb-0">{{ $material?->title ?? 'Reading Passage' }}</h2>
                    <span class="badge bg-light text-dark">{{ $material?->word_count ?? 0 }} words</span>
                </div>
                <div class="card-body">
                    @if($material)
                        <div class="p-4 bg-light rounded" style="font-size: 1.3rem; line-height: 2;">{{ $material->content }}</div>
                    @else
                        <p class="text-muted mb-0">
                            No passage has been chosen for {{ $firstName }} yet. Pick one on the group setup
                            screen before conducting this reading.
                        </p>
                    @endif
                </div>
            </div>

            @if($questions->isNotEmpty())
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0"><i class="bi bi-patch-question me-1"></i> Comprehension Questions</h2>
                        @if($answers->isNotEmpty())
                            <span class="badge bg-info">Answered — {{ $entry->comprehensionScore() }}%</span>
                        @else
                            <span class="badge bg-warning text-dark">Required before sending</span>
                        @endif
                    </div>
                    @if($entry->result)
                        {{--
                            Analysed. The score in the result was worked out from
                            these answers, and changing them would leave that
                            figure reporting something the answer rows no longer
                            say - so answers() refuses, and the form is not
                            offered rather than offered and then refused after
                            the child has already answered. What they answered is
                            still shown, read-only.
                        --}}
                        <div class="card-body">
                            <p class="small text-muted">
                                This reading has been analysed, so these answers are now part of its result and
                                cannot be changed. To score the saved recording again, open the result.
                            </p>
                            @if($answers->isNotEmpty())
                                <ol class="mb-0">
                                    @foreach($answers as $answer)
                                        <li class="mb-2">
                                            <div>{{ $answer->question_text }}</div>
                                            <div class="small">
                                                @if($answer->is_correct)
                                                    <span class="text-success"><i class="bi bi-check-circle me-1"></i>{{ $answer->selected_text }}</span>
                                                @else
                                                    <span class="text-danger"><i class="bi bi-x-circle me-1"></i>{{ $answer->selected_text ?? 'No answer' }}</span>
                                                    <span class="text-muted ms-2">Correct: {{ $answer->correct_text }}</span>
                                                @endif
                                            </div>
                                        </li>
                                    @endforeach
                                </ol>
                            @else
                                <p class="small text-muted mb-0">No answers were stored for this reading.</p>
                            @endif
                        </div>
                    @else
                    <div class="card-body" data-answers>
                        <p class="small text-muted">
                            Ask each question aloud and mark what {{ $firstName }} answered — or hand them the
                            device to tap their own answers. Answers are stored before anything is sent, so a
                            service outage can never cost {{ $firstName }} this work.
                        </p>

                        @foreach($questions as $i => $question)
                            @php $given = $answers->firstWhere('question_id', $question->id); @endphp
                            <div class="mb-3 pb-2 border-bottom" data-question="{{ $question->id }}">
                                <p class="fw-semibold mb-2">{{ $i + 1 }}. {{ $question->question }}</p>
                                <div class="d-flex flex-column gap-1">
                                    @foreach($question->getOptions() as $letter => $text)
                                        <label class="border rounded px-3 py-2" style="cursor: pointer;">
                                            <input class="form-check-input me-2" type="radio"
                                                   name="answers[{{ $question->id }}]" value="{{ $letter }}"
                                                   @checked($given && $given->selected_option === $letter)>
                                            <strong class="me-1">{{ $letter }}.</strong> {{ $text }}
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach

                        <button type="button" class="btn btn-outline-primary btn-sm" data-save-answers>
                            <i class="bi bi-save me-1"></i> Save answers
                        </button>
                        <p class="small mb-0 mt-2" data-answers-message role="status" aria-live="polite"></p>
                    </div>
                    @endif
                </div>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        // The board's own poll, pointed at this one card: it patches the chip,
        // the label and the actions in place and never reloads the page.
        window.BATCH_BOARD = {
            pollUrl: '{{ route("assessments.batch.poll", $batch) }}',
            boardUrl: '{{ route("assessments.batch.board", $batch) }}',
            // Where a card whose new state needs server-rendered markup is
            // fetched from. This page, not the board: the board's copy of this
            // card carries the buttons that lead back here, and swapping it in
            // would put them on the panel the moment a reading finished.
            cardUrl: '{{ route("assessments.batch.entry", [$batch, $entry]) }}',
            status: '{{ $batch->status }}',
        };

        window.BATCH_PANEL = {
            uploadUrl: '{{ route("assessments.batch.upload-audio", [$batch, $entry]) }}',
            answersUrl: '{{ route("assessments.batch.answers", [$batch, $entry]) }}',
            sendUrl: '{{ route("assessments.batch.send", [$batch, $entry]) }}',
            // Whether the state offers Send at all. The script may only ever
            // narrow this, never widen it: a queued or failed card must not get
            // a Send button that reports success for a stamp that no-ops.
            sendOffered: {{ $state['action'] === 'Send for analysis' ? 'true' : 'false' }},
            boardUrl: '{{ route("assessments.batch.board", $batch) }}',
            csrf: '{{ csrf_token() }}',
        };
    </script>
    <script src="{{ asset('js/batch-board.js') }}"></script>
    <script src="{{ asset('js/batch-recorder.js') }}"></script>
@endpush
