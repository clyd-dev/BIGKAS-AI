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
            <option value="english" {{ old('language', $material->language ?? '') === 'english' ? 'selected' : '' }}>English</option>
            <option value="filipino" {{ old('language', $material->language ?? '') === 'filipino' ? 'selected' : '' }}>Filipino</option>
            <option value="hiligaynon" {{ old('language', $material->language ?? '') === 'hiligaynon' ? 'selected' : '' }}>Hiligaynon</option>
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
        <label for="grade_level" class="form-label">Grade Level <span class="text-danger">*</span></label>
        <select class="form-select @error('grade_level') is-invalid @enderror" id="grade_level" name="grade_level" required>
            <option value="">Select</option>
            @for($i = 1; $i <= 6; $i++)
                <option value="{{ $i }}" {{ old('grade_level', $material->grade_level ?? '') == $i ? 'selected' : '' }}>Grade {{ $i }}</option>
            @endfor
        </select>
        @error('grade_level') <div class="invalid-feedback">{{ $message }}</div> @enderror
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
        <label for="category" class="form-label">Category</label>
        <select class="form-select" id="category" name="category">
            <option value="narrative" {{ old('category', $material->category ?? '') === 'narrative' ? 'selected' : '' }}>Narrative</option>
            <option value="informational" {{ old('category', $material->category ?? '') === 'informational' ? 'selected' : '' }}>Informational</option>
            <option value="literary" {{ old('category', $material->category ?? '') === 'literary' ? 'selected' : '' }}>Literary</option>
            <option value="poetry" {{ old('category', $material->category ?? '') === 'poetry' ? 'selected' : '' }}>Poetry</option>
        </select>
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
