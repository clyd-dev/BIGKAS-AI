@extends('layouts.student')

@section('title', 'Flash Cards')

@section('content')

    {{-- Header --}}
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h4 class="fw-bold mb-0">🃏 Flash Cards</h4>
        <span class="badge rounded-pill px-3 py-2" style="background: var(--kid-primary); font-size: 0.8rem;" id="flashcardCounter">
            1 / {{ $cards->count() }}
        </span>
    </div>

    @if($cards->count() === 0)
        <div class="kid-card text-center py-5">
            <div style="font-size: 4rem;" class="mb-3">📭</div>
            <h5 class="fw-bold">No Cards Available</h5>
            <p class="text-muted mb-3">There are no reading materials at your level yet.</p>
            <a href="{{ route('student.dashboard') }}" class="btn btn-kid btn-kid-primary">Back to Home</a>
        </div>
    @else
        {{-- Progress Bar --}}
        <div class="mb-3" style="background: #E8E6FF; border-radius: 10px; height: 8px; overflow: hidden;">
            <div id="flashcardProgress" style="width: 0%; height: 100%; background: linear-gradient(90deg, var(--kid-primary), var(--kid-secondary)); border-radius: 10px; transition: width 0.3s;"></div>
        </div>

        {{-- Flash Card Area --}}
        <div id="flashcardArea" data-cards='@json($cards)'>

            {{-- Card --}}
            <div class="flashcard-container mb-4" onclick="flipCard()">
                <div class="flashcard" id="flashcard">
                    <div class="flashcard-front">
                        <span id="flashcardWord">{{ $cards->first() }}</span>
                    </div>
                    <div class="flashcard-back">
                        <p class="text-muted mb-2" style="font-size: 0.85rem;">Can you read this word?</p>
                        <h3 class="fw-bold" style="color: var(--kid-primary);" id="flashcardWordBack">{{ $cards->first() }}</h3>
                        <p class="text-muted" style="font-size: 0.8rem;">
                            Tap "I Know It" or "Still Learning"
                        </p>
                    </div>
                </div>
            </div>

            <p class="text-center text-muted mb-3" style="font-size: 0.8rem;">
                <i class="bi bi-hand-index"></i> Tap the card to flip it
            </p>

            {{-- Action Buttons --}}
            <div class="d-flex gap-3 justify-content-center">
                <button class="btn btn-kid btn-kid-danger px-4 py-3" onclick="flashCardDontKnow()" style="min-width: 140px;">
                    <i class="bi bi-emoji-frown"></i><br>
                    <span style="font-size: 0.85rem;">Still Learning</span>
                </button>
                <button class="btn btn-kid btn-kid-success px-4 py-3" onclick="flashCardKnow()" style="min-width: 140px;">
                    <i class="bi bi-emoji-smile"></i><br>
                    <span style="font-size: 0.85rem;">I Know It!</span>
                </button>
            </div>
        </div>

        {{-- Results (hidden by default) --}}
        <div id="flashcardResult" class="d-none">
            <div class="kid-card text-center py-5">
                <div style="font-size: 4rem;" class="mb-3">🎉</div>
                <h4 class="fw-bold">Practice Complete!</h4>

                <div class="row g-3 my-4 justify-content-center">
                    <div class="col-4">
                        <div class="kid-card py-3 text-center" style="background: #E6FFF5;">
                            <div class="fw-bold" style="font-size: 1.8rem; color: var(--kid-success);" id="resultCorrect">0</div>
                            <div style="font-size: 0.75rem; color: var(--kid-text-light);">Correct</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="kid-card py-3 text-center">
                            <div class="fw-bold" style="font-size: 1.8rem; color: var(--kid-text);" id="resultTotal">0</div>
                            <div style="font-size: 0.75rem; color: var(--kid-text-light);">Total</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="kid-card py-3 text-center" style="background: var(--kid-primary-light);">
                            <div class="fw-bold" style="font-size: 1.8rem; color: var(--kid-primary);" id="resultPercent">0%</div>
                            <div style="font-size: 0.75rem; color: var(--kid-text-light);">Score</div>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2 justify-content-center">
                    <a href="{{ route('student.flashcards') }}" class="btn btn-kid btn-kid-primary">
                        Play Again
                    </a>
                    <a href="{{ route('student.dashboard') }}" class="btn btn-kid btn-kid-warning">
                        Home
                    </a>
                </div>
            </div>
        </div>

        <input type="hidden" id="flashcardSaveUrl" value="{{ route('student.flashcards.save') }}">
    @endif

@endsection
