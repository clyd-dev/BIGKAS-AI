@extends('layouts.app')

@section('title', 'Phonemic Awareness Practice')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-ear me-2"></i>Phonemic Awareness</h4>
        <a href="{{ route('practice.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back</a>
    </div>

    {{-- Select Learner --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form id="practiceForm" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small">Learner</label>
                    <select id="learnerSelect" class="form-select form-select-sm">
                        <option value="">Select learner...</option>
                        @foreach($learners ?? [] as $learner)
                            <option value="{{ $learner->id }}">{{ $learner->full_name }} (Grade {{ $learner->grade_level }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Category</label>
                    <select id="categorySelect" class="form-select form-select-sm">
                        <option value="sound_matching">Sound Matching</option>
                        <option value="rhyming">Rhyming Words</option>
                        <option value="syllable_counting">Syllable Counting</option>
                        <option value="phoneme_blending">Phoneme Blending</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="button" id="startBtn" class="btn btn-sm btn-primary w-100">
                        <i class="bi bi-play-fill me-1"></i> Start
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Practice Area --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5" id="practiceArea">
            <i class="bi bi-ear display-1 text-muted opacity-25"></i>
            <p class="text-muted mt-3">Select a learner and category to begin practice.</p>
        </div>
    </div>
@endsection
