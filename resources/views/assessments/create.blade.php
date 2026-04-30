@extends('layouts.app')

@section('title', 'New Assessment')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-plus-circle me-2"></i>New Assessment</h4>
        <a href="{{ route('assessments.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back</a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('assessments.store') }}">
                @csrf

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="learner_id" class="form-label">Select Learner <span class="text-danger">*</span></label>
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

                    <div class="col-md-6">
                        <label for="material_id" class="form-label">Select Reading Material <span class="text-danger">*</span></label>
                        <select class="form-select @error('material_id') is-invalid @enderror" id="material_id" name="material_id" required>
                            <option value="">Choose a material...</option>
                            @foreach($materials ?? [] as $material)
                                <option value="{{ $material->id }}" {{ old('material_id') == $material->id ? 'selected' : '' }}>
                                    {{ $material->title }} ({{ ucfirst($material->language) }}, {{ $material->word_count }} words)
                                </option>
                            @endforeach
                        </select>
                        @error('material_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4">
                        <label for="language" class="form-label">Assessment Language</label>
                        <select class="form-select" id="language" name="language">
                            <option value="english" {{ old('language') === 'english' ? 'selected' : '' }}>English</option>
                            <option value="filipino" {{ old('language') === 'filipino' ? 'selected' : '' }}>Filipino</option>
                            <option value="hiligaynon" {{ old('language') === 'hiligaynon' ? 'selected' : '' }}>Hiligaynon</option>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label for="assessment_type" class="form-label">Assessment Type</label>
                        <select class="form-select" id="assessment_type" name="assessment_type">
                            <option value="oral_reading" {{ old('assessment_type') === 'oral_reading' ? 'selected' : '' }}>Oral Reading</option>
                            <option value="comprehension" {{ old('assessment_type') === 'comprehension' ? 'selected' : '' }}>Comprehension</option>
                            <option value="combined" {{ old('assessment_type') === 'combined' ? 'selected' : '' }}>Combined</option>
                        </select>
                    </div>
                </div>

                <div class="mt-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-play-circle me-1"></i> Create Assessment
                    </button>
                    <a href="{{ route('assessments.index') }}" class="btn btn-outline-secondary ms-2">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
