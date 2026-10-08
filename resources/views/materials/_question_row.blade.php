{{--
    One comprehension question row.

    $index — array index, or the literal __INDEX__ placeholder when this row is
             rendered inside the <template> that JS clones for new questions.
    $q     — existing values as an array (question, question_type, option_a..d,
             correct_option), or null for a blank row.
--}}
@php
    $q = $q ?? [];
    $val = fn ($key, $default = '') => $q[$key] ?? $default;
@endphp
<div class="card border-0 bg-light mb-3 question-row">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-2">
            <span class="fw-semibold small text-muted question-row-label">Question</span>
            <button type="button" class="btn btn-sm btn-outline-danger remove-question" title="Remove question">
                <i class="bi bi-trash"></i>
            </button>
        </div>
        <div class="row g-2">
            <div class="col-md-8">
                <label class="form-label small">Question</label>
                <input type="text" class="form-control form-control-sm"
                       name="questions[{{ $index }}][question]" value="{{ $val('question') }}" required>
            </div>
            <div class="col-md-4">
                <label class="form-label small">Question Type</label>
                <select class="form-select form-select-sm" name="questions[{{ $index }}][question_type]">
                    <option value="literal" {{ $val('question_type', 'literal') === 'literal' ? 'selected' : '' }}>Literal</option>
                    <option value="inferential" {{ $val('question_type') === 'inferential' ? 'selected' : '' }}>Inferential</option>
                </select>
            </div>
            @foreach(['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D'] as $key => $letter)
                <div class="col-md-6">
                    <label class="form-label small">Option {{ $letter }}</label>
                    <input type="text" class="form-control form-control-sm"
                           name="questions[{{ $index }}][option_{{ $key }}]" value="{{ $val('option_' . $key) }}" required>
                </div>
            @endforeach
            <div class="col-12">
                <label class="form-label small">Correct Answer</label>
                <div class="d-flex gap-3">
                    @foreach(['A', 'B', 'C', 'D'] as $letter)
                        <div class="form-check">
                            <input class="form-check-input" type="radio"
                                   name="questions[{{ $index }}][correct_option]" value="{{ $letter }}"
                                   {{ $val('correct_option') === $letter ? 'checked' : '' }} required>
                            <label class="form-check-label">{{ $letter }}</label>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>
