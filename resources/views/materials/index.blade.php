@extends('layouts.app')

@section('title', 'Reading Materials')

@section('content')
    @php $isAdmin = auth()->user()->isAdmin(); @endphp

    <x-page-header title="Reading Materials" icon="bi-journal-text">
        <x-slot:actions>
            <a href="{{ route('materials.create') }}" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-circle me-1"></i> Add Material
            </a>
        </x-slot:actions>
    </x-page-header>

    @unless($isAdmin)
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-1"></i> Showing materials for your assigned grade{{ $lockedGrade ? " (Grade {$lockedGrade})" : '' }} only.
        </div>
    @endunless

    {{-- Filters --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('materials.index') }}" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Search</label>
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Title..." value="{{ request('search') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Language</label>
                    <select name="language" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="en" {{ request('language') === 'en' ? 'selected' : '' }}>English</option>
                        <option value="fil" {{ request('language') === 'fil' ? 'selected' : '' }}>Filipino</option>
                        </select>
                </div>
                @if($isAdmin)
                    <div class="col-md-2">
                        <label class="form-label small">Grade</label>
                        <select name="grade_level" class="form-select form-select-sm">
                            <option value="">All</option>
                            @for($i = 3; $i <= 6; $i++)
                                <option value="{{ $i }}" {{ request('grade_level') == $i ? 'selected' : '' }}>Grade {{ $i }}</option>
                            @endfor
                        </select>
                    </div>
                @endif
                <div class="col-md-2">
                    <label class="form-label small">Difficulty</label>
                    <select name="difficulty" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="easy" {{ request('difficulty') === 'easy' ? 'selected' : '' }}>Easy</option>
                        <option value="medium" {{ request('difficulty') === 'medium' ? 'selected' : '' }}>Medium</option>
                        <option value="hard" {{ request('difficulty') === 'hard' ? 'selected' : '' }}>Hard</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Type</label>
                    <select name="type" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="oral_reading" {{ request('type') === 'oral_reading' ? 'selected' : '' }}>Oral Reading</option>
                        <option value="comprehension" {{ request('type') === 'comprehension' ? 'selected' : '' }}>Comprehension</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-search me-1"></i> Filter</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Materials Grid --}}
    <div class="row g-3">
        @forelse($materials ?? [] as $material)
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h6 class="card-title mb-0">{{ $material->title }}</h6>
                            <span class="badge {{ $material->difficulty === 'easy' ? 'bg-success' : ($material->difficulty === 'hard' ? 'bg-danger' : 'bg-warning text-dark') }}">
                                {{ ucfirst($material->difficulty) }}
                            </span>
                        </div>
                        <p class="text-muted small mb-2">{{ Str::limit($material->content, 100) }}</p>
                        <div class="d-flex gap-2 flex-wrap mb-3">
                            <span class="badge bg-light text-dark"><i class="bi bi-translate me-1"></i>{{ ucfirst($material->language) }}</span>
                            <span class="badge bg-light text-dark"><i class="bi bi-mortarboard me-1"></i>Grade {{ $material->grade_level }}</span>
                            <span class="badge bg-light text-dark"><i class="bi bi-card-text me-1"></i>{{ $material->word_count }} words</span>
                        </div>
                    </div>
                    <div class="card-footer bg-white border-top-0">
                        <a href="{{ route('materials.show', $material) }}" class="btn btn-sm btn-primary w-100">
                            <i class="bi bi-eye me-1"></i> View
                        </a>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12">
                <div class="text-center text-muted py-5">
                    <i class="bi bi-journal-x display-4"></i>
                    <p class="mt-2">No materials found. <a href="{{ route('materials.create') }}">Add your first material</a></p>
                </div>
            </div>
        @endforelse
    </div>

    @if(method_exists($materials ?? collect(), 'links'))
        <div class="mt-3">{{ $materials->links() }}</div>
    @endif
@endsection
