@extends('layouts.app')

@section('title', $batch->title ?: 'Group Assessment')

@push('styles')
    {{-- The good / ok / help / none tone palette, shared with the parent portal. --}}
    <link href="{{ asset('css/parent.css') }}" rel="stylesheet">
    <link href="{{ asset('css/batch-board.css') }}" rel="stylesheet">
@endpush

@section('content')
    <x-page-header :title="$batch->title ?: 'Group Assessment'" icon="bi-people"
                   :back="route('assessments.index')" back-label="Assessments"
                   :subtitle="'One card per child. Tap a card to work with that child.'">
        <x-slot:actions>
            {{--
                Send all ready is the only bulk action here. Sending asks the
                machine to measure something and a bad send costs lane time;
                accepting a verdict writes a judgement about a child into their
                permanent record, so there is no bulk verdict control anywhere on
                this page. Each child's verdict goes through the existing
                per-child results screen.
            --}}
            <form method="POST" action="{{ route('assessments.batch.send-all', $batch) }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-primary btn-sm" @disabled($readyToSend === 0)>
                    <i class="bi bi-send me-1"></i>
                    Send all ready <span class="badge bg-light text-dark ms-1" data-ready-count>{{ $readyToSend }}</span>
                </button>
            </form>

            @if($batch->status !== \App\Models\AssessmentBatch::STATUS_CANCELLED)
                <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#cancelBatchModal">
                    <i class="bi bi-slash-circle me-1"></i> Cancel batch
                </button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if($batch->status === \App\Models\AssessmentBatch::STATUS_CANCELLED)
        {{-- A cancelled batch is the record of what was accomplished before the
             teacher stopped, so the cards stay. --}}
        <div class="alert alert-secondary d-flex align-items-start gap-2" role="status">
            <i class="bi bi-slash-circle mt-1"></i>
            <div>
                <div class="fw-semibold">This batch was cancelled{{ $batch->cancelled_at ? ' on ' . $batch->cancelled_at->format('j M Y, g:ia') : '' }}.</div>
                <div class="small text-muted">
                    The readings below are what was done before it stopped — they are kept. Nothing more can be
                    recorded or sent from this batch; start a new one for any child who still needs a reading.
                </div>
            </div>
        </div>
    @endif

    <div class="alert alert-light border d-flex align-items-start gap-2" role="status">
        <i class="bi bi-shield-check mt-1"></i>
        <div>
            <div>Analysis continues even if you close this page — you can come back any time.</div>
            <div class="small text-muted" data-estimate>
                @if($estimate['remaining'] > 0)
                    {{ $estimate['remaining'] }} {{ Str::plural('reading', $estimate['remaining']) }} still to score
                    @if($estimate['ahead'] > 0)
                        ({{ $estimate['ahead'] }} ahead of this batch)
                    @endif
                    — about {{ max(1, (int) round($estimate['seconds'] / 60)) }} min left.
                @else
                    Nothing is waiting to be scored right now.
                @endif
            </div>
        </div>
    </div>

    @if($entries->isEmpty())
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center text-muted py-5">
                <i class="bi bi-people fs-1 d-block mb-2"></i>
                <p class="mb-0">No children on this board.</p>
                <p class="small mb-0">Every child in this batch has moved out of your classes.</p>
            </div>
        </div>
    @else
        <div class="row g-3" id="batchBoardGrid">
            @foreach($entries as $entry)
                @include('assessments.batch._card', ['batch' => $batch, 'entry' => $entry, 'state' => $states[$entry->id], 'joinCode' => $joinCodes[$entry->id] ?? null])
            @endforeach
        </div>
    @endif

    @if($batch->status !== \App\Models\AssessmentBatch::STATUS_CANCELLED)
        {{--
            The confirmation names the consequences rather than asking a generic
            "are you sure?": a teacher pressing this needs to know which children
            keep their results and what is about to be stopped. The sentence is
            built from BatchEntryState in the controller, so it cannot promise
            something the cards do not show.
        --}}
        <div class="modal fade" id="cancelBatchModal" tabindex="-1" aria-labelledby="cancelBatchModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="cancelBatchModalLabel">Cancel this batch?</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-2">{{ $cancelConsequences }}</p>
                        <p class="small text-muted mb-0">
                            No child’s result is ever deleted by this. Readings already measured stay on this board
                            and on each child’s own history.
                        </p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep going</button>
                        <form method="POST" action="{{ route('assessments.batch.cancel', $batch) }}">
                            @csrf
                            <button type="submit" class="btn btn-danger">
                                <i class="bi bi-slash-circle me-1"></i> Cancel batch
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
    <script>
        window.BATCH_BOARD = {
            pollUrl: '{{ route("assessments.batch.poll", $batch) }}',
            // Used only to re-render a single card whose new state needs
            // server-side markup; the page itself is never reloaded.
            boardUrl: '{{ route("assessments.batch.board", $batch) }}',
            status: '{{ $batch->status }}',
        };
    </script>
    <script src="{{ asset('js/batch-board.js') }}"></script>
@endpush
