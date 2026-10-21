{{--
    One child's panel: where the teacher conducts this reading, listens to it
    and sends it.

    Two ways to read are offered side by side, not chosen at setup: the teacher
    can conduct the reading on this device, or hand the child a join code to
    read on their own. Which one suits a child is known in the moment, not an
    hour earlier.

    The chip, the label and the one action come from the board's own
    _card.blade.php, included here rather than written again, so the panel can
    never say something different about a child than the board does. The card is
    kept up to date by public/js/batch-board.js - the same poll, patching this
    one card in place - so sending or recording here never reloads the page and
    never stops a recording the teacher is listening to.

    Expects: $batch, $entry, $state, $material, $questions, $answers, $session.
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
                   :subtitle="'Record this reading here, or give ' . $firstName . ' the join code to read on their own device.'" />

    <div class="row g-3">
        <div class="col-lg-5">
            {{-- What the board says about this child, rendered by the board's own card. --}}
            <div class="row g-3 mb-3">
                @include('assessments.batch._card', ['batch' => $batch, 'entry' => $entry, 'state' => $state])
            </div>

            <div class="card border-0 shadow-sm mb-3">
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
                    <p class="small text-muted">
                        Sit with {{ $firstName }}, tap Start reading, and let them read the passage aloud.
                        Nothing is sent for analysis until you tap Send for analysis.
                    </p>

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

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="bi bi-phone me-1"></i> On {{ $firstName }}'s own device</h2>
                </div>
                <div class="card-body">
                    @if($session)
                        <p class="small text-muted mb-2">
                            {{ $firstName }} signs in to the student portal and enters this code to read on their
                            own device. The recording lands on this panel either way.
                        </p>
                        <div class="d-flex align-items-baseline gap-2">
                            <span class="small text-muted">Join code:</span>
                            <span class="fs-4 fw-bold text-primary font-monospace">{{ $session->session_code }}</span>
                        </div>
                    @else
                        {{-- Every entry in a batch is issued a code when the batch is
                             created, so this is only ever empty for a batch made
                             before join codes existed. --}}
                        <p class="small text-muted mb-0">
                            No join code was issued for {{ $firstName }}, so this reading is conducted
                            here on your device.
                        </p>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            @if($questions->isNotEmpty())
                <div class="card border-0 shadow-sm mb-3">
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

            <div class="card border-0 shadow-sm">
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
