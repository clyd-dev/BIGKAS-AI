{{-- Material Form Partial --}}
<div class="row g-3">
    <div class="col-md-8">
        <label for="title" class="form-label">Title <span class="text-danger">*</span></label>
        <input type="text" class="form-control @error('title') is-invalid @enderror"
               id="title" name="title" value="{{ old('title', $material->title ?? '') }}" required>
        @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="col-md-4">
        <label for="language" class="form-label">Language <span class="text-danger">*</span></label>
        <select class="form-select @error('language') is-invalid @enderror" id="language" name="language" required>
            <option value="">Select</option>
            <option value="en" {{ old('language', $material->language ?? '') === 'english' ? 'selected' : '' }}>English</option>
            <option value="fil" {{ old('language', $material->language ?? '') === 'filipino' ? 'selected' : '' }}>Filipino</option>
        </select>
        @error('language') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="col-12">
        <label for="content" class="form-label">Reading Passage <span class="text-danger">*</span></label>
        <textarea class="form-control @error('content') is-invalid @enderror"
                  id="content" name="content" rows="8" required>{{ old('content', $material->content ?? '') }}</textarea>
        <div class="form-text">Word count: <span id="wordCount">0</span></div>
        @error('content') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="col-md-3">
        <label for="grade_level" class="form-label">Grade Level</label>
        @if(isset($lockedClass) && $lockedClass)
            <input type="text" class="form-control bg-light" value="Grade {{ $lockedClass->grade_level }}" readonly>
            <input type="hidden" name="grade_level" value="{{ $lockedClass->grade_level }}">
        @elseif(isset($material) && !isset($lockedClass))
            {{-- Teacher editing an existing material: grade stays put. --}}
            <input type="text" class="form-control bg-light" value="Grade {{ $material->grade_level }}" readonly>
        @else
            <select class="form-select @error('grade_level') is-invalid @enderror" id="grade_level" name="grade_level" required>
                <option value="">Select</option>
                @for($i = 3; $i <= 6; $i++)
                    <option value="{{ $i }}" {{ old('grade_level', $material->grade_level ?? '') == $i ? 'selected' : '' }}>Grade {{ $i }}</option>
                @endfor
            </select>
            @error('grade_level') <div class="invalid-feedback">{{ $message }}</div> @enderror
        @endif
    </div>
    <div class="col-md-3">
        <label for="difficulty" class="form-label">Difficulty</label>
        <select class="form-select" id="difficulty" name="difficulty">
            <option value="easy" {{ old('difficulty', $material->difficulty ?? 'medium') === 'easy' ? 'selected' : '' }}>Easy</option>
            <option value="medium" {{ old('difficulty', $material->difficulty ?? 'medium') === 'medium' ? 'selected' : '' }}>Medium</option>
            <option value="hard" {{ old('difficulty', $material->difficulty ?? 'medium') === 'hard' ? 'selected' : '' }}>Hard</option>
        </select>
    </div>
    <div class="col-md-3">
        <label for="type" class="form-label">Type <span class="text-danger">*</span></label>
        <select class="form-select @error('type') is-invalid @enderror" id="type" name="type" required>
            <option value="oral_reading" {{ old('type', $material->type ?? 'oral_reading') === 'oral_reading' ? 'selected' : '' }}>Oral Reading</option>
            <option value="comprehension" {{ old('type', $material->type ?? '') === 'comprehension' ? 'selected' : '' }}>Comprehension</option>
        </select>
        @error('type') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="col-md-3">
        <label for="source" class="form-label">Source</label>
        <input type="text" class="form-control" id="source" name="source"
               value="{{ old('source', $material->source ?? '') }}" placeholder="e.g., DepEd Module">
    </div>
</div>

@push('scripts')
<script>
    const contentEl = document.getElementById('content');
    const wordCountEl = document.getElementById('wordCount');
    function updateWordCount() {
        const text = contentEl.value.trim();
        wordCountEl.textContent = text ? text.split(/\s+/).length : 0;
    }
    contentEl.addEventListener('input', updateWordCount);
    updateWordCount();
</script>
@endpush
