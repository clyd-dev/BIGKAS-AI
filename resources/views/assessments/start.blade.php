@extends('layouts.app')

@section('title', 'Start Assessment')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-play-circle me-2"></i>Start Assessment for {{ $learner->getFullName() }}</h4>
        <a href="{{ route('learners.show', $learner) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back</a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-1"></i> Select a reading material for <strong>{{ $learner->getFullName() }}</strong> (Grade {{ $learner->grade_level }}).
            </div>

            <form method="POST" action="{{ route('assessments.store') }}">
                @csrf
                <input type="hidden" name="learner_id" value="{{ $learner->id }}">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="material_id" class="form-label">Reading Material <span class="text-danger">*</span></label>
                        <select class="form-select" id="material_id" name="material_id" required>
                            <option value="">Choose a material...</option>
                            @foreach($materials ?? [] as $material)
                                <option value="{{ $material->id }}">
                                    {{ $material->title }} ({{ ucfirst($material->language) }}, {{ $material->word_count }} words)
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="language" class="form-label">Language</label>
                        <select class="form-select" id="language" name="language">
                            <option value="english">English</option>
                            <option value="filipino">Filipino</option>
                            <option value="hiligaynon">Hiligaynon</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="assessment_type" class="form-label">Type</label>
                        <select class="form-select" id="assessment_type" name="assessment_type">
                            <option value="oral_reading">Oral Reading</option>
                            <option value="comprehension">Comprehension</option>
                            <option value="combined">Combined</option>
                        </select>
                    </div>
                </div>

                <div class="mt-4">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-play-fill me-1"></i> Start Assessment
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection
