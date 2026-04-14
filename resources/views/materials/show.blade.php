@extends('layouts.app')

@section('title', $material->title)

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-journal-text me-2"></i>{{ $material->title }}</h4>
        <div>
            <a href="{{ route('materials.edit', $material) }}" class="btn btn-outline-warning btn-sm"><i class="bi bi-pencil me-1"></i> Edit</a>
            <a href="{{ route('materials.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back</a>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0">Reading Passage</h6></div>
                <div class="card-body">
                    <div class="reading-passage p-3 bg-light rounded" style="font-size: 1.1rem; line-height: 1.8;">
                        {{ $material->content }}
                    </div>
                </div>
            </div>

            {{-- Comprehension Questions --}}
            @if($material->comprehensionQuestions->count())
                <div class="card border-0 shadow-sm mt-3">
                    <div class="card-header bg-white"><h6 class="mb-0">Comprehension Questions</h6></div>
                    <div class="card-body">
                        <ol>
                            @foreach($material->comprehensionQuestions as $q)
                                <li class="mb-2">
                                    <strong>{{ $q->question }}</strong>
                                    @if($q->correct_answer)
                                        <br><small class="text-success"><i class="bi bi-check me-1"></i>{{ $q->correct_answer }}</small>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    </div>
                </div>
            @endif
        </div>

        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0">Details</h6></div>
                <div class="card-body">
                    <table class="table table-borderless table-sm mb-0">
                        <tr><th class="text-muted">Language</th><td>{{ ucfirst($material->language) }}</td></tr>
                        <tr><th class="text-muted">Grade Level</th><td>Grade {{ $material->grade_level }}</td></tr>
                        <tr><th class="text-muted">Difficulty</th><td>
                            <span class="badge {{ $material->difficulty === 'easy' ? 'bg-success' : ($material->difficulty === 'hard' ? 'bg-danger' : 'bg-warning text-dark') }}">
                                {{ ucfirst($material->difficulty) }}
                            </span>
                        </td></tr>
                        <tr><th class="text-muted">Category</th><td>{{ ucfirst($material->category ?? 'N/A') }}</td></tr>
                        <tr><th class="text-muted">Word Count</th><td>{{ $material->word_count }}</td></tr>
                        <tr><th class="text-muted">Source</th><td>{{ $material->source ?? 'N/A' }}</td></tr>
                        <tr><th class="text-muted">Created</th><td>{{ $material->created_at?->format('M d, Y') }}</td></tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
