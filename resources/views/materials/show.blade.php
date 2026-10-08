@extends('layouts.app')

@section('title', $material->title)

@section('content')
    <x-page-header :title="$material->title" icon="bi-journal-text"
                   :back="route('materials.index')" back-label="Materials" />

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
                        <tr><th class="text-muted">Type</th><td>
                            <span class="badge {{ $material->isComprehensionType() ? 'bg-info' : 'bg-primary' }}">
                                {{ $material->getTypeName() }}
                            </span>
                        </td></tr>
                        <tr><th class="text-muted">Word Count</th><td>{{ $material->word_count }}</td></tr>
                        <tr><th class="text-muted">Source</th><td>{{ $material->source ?? 'N/A' }}</td></tr>
                        <tr><th class="text-muted">Created</th><td>{{ $material->created_at?->format('M d, Y') }}</td></tr>
                    </table>
                </div>
            </div>

            {{-- Danger Zone --}}
            <div class="card border-danger-subtle shadow-sm mt-3">
                <div class="card-header bg-danger-subtle text-danger-emphasis">
                    <h6 class="mb-0"><i class="bi bi-exclamation-triangle me-1"></i> Danger Zone</h6>
                </div>
                <div class="card-body d-flex gap-2">
                    <a href="{{ route('materials.edit', $material) }}" class="btn btn-outline-warning flex-fill">
                        <i class="bi bi-pencil me-1"></i> Edit Material
                    </a>
                    <form method="POST" action="{{ route('materials.destroy', $material) }}"
                          class="flex-fill" onsubmit="return confirm('Deactivate this material? It will no longer appear in listings.')">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger w-100">
                            <i class="bi bi-trash me-1"></i> Delete Material
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
