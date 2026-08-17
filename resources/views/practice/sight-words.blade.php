@extends('layouts.app')

@section('title', 'Sight Words Practice')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-eye me-2"></i>Sight Words</h4>
        <a href="{{ route('practice.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back</a>
    </div>

    {{-- Select Learner --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form id="practiceForm" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label for="learner_id" class="form-label">Learner <span class="text-danger">*</span></label>
                    <select class="form-select @error('learner_id') is-invalid @enderror" id="learner_id" name="learner_id" required>
                        <option value="">Choose a learner...</option>
                        @foreach($learners as $learner)
                            <option value="{{ $learner->id }}" {{ old('learner_id') == $learner->id ? 'selected' : '' }}>
                                {{ $learner->full_name }} (Grade {{ $learner->grade_level }})
                            </option>
                        @endforeach
                    </select>
                    @error('learner_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Level</label>
                    <select id="levelSelect" class="form-select form-select-sm">
                        <option value="pre_primer">Pre-Primer</option>
                        <option value="primer">Primer</option>
                        <option value="grade_1">Grade 1</option>
                        <option value="grade_2">Grade 2</option>
                        <option value="grade_3">Grade 3</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="button" id="startBtn" class="btn btn-sm btn-success w-100">
                        <i class="bi bi-play-fill me-1"></i> Start
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Practice Area --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5" id="practiceArea">
            <i class="bi bi-eye display-1 text-muted opacity-25"></i>
            <p class="text-muted mt-3">Select a learner and level to begin sight word practice.</p>
        </div>
    </div>
@endsection
