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
        </x-slot:actions>
    </x-page-header>

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
