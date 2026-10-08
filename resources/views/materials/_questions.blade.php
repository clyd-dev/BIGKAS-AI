{{--
    Comprehension Questions builder — shared by create and edit.
    Visible only while Type = Comprehension (toggled by the #type select).

    Existing rows come from old() after a validation error, otherwise from the
    material being edited.
--}}
@php
    $existingQuestions = collect(old('questions'));

    if ($existingQuestions->isEmpty() && isset($material)) {
        $existingQuestions = $material->comprehensionQuestions->map(fn ($q) => [
            'question' => $q->question,
            'question_type' => $q->question_type,
            'option_a' => $q->option_a,
            'option_b' => $q->option_b,
            'option_c' => $q->option_c,
            'option_d' => $q->option_d,
            'correct_option' => $q->getCorrectOptionLetter(),
        ]);
    }

    $isComprehension = old('type', $material->type ?? 'oral_reading') === 'comprehension';
@endphp

<div id="questionsBuilder" class="mt-4 pt-3 border-top {{ $isComprehension ? '' : 'd-none' }}">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h6 class="mb-0">Comprehension Questions</h6>
            <small class="text-muted">Asked after the learner finishes reading this passage.</small>
        </div>
        <button type="button" id="addQuestionBtn" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-plus-circle me-1"></i> Add Question
        </button>
    </div>

    @error('questions') <div class="alert alert-danger">{{ $message }}</div> @enderror
    @if($errors->hasAny(['questions.*.question', 'questions.*.option_a', 'questions.*.correct_option']))
        <div class="alert alert-danger">Every question needs text, all four options, and a correct answer.</div>
    @endif

    <div id="questionsList">
        @foreach($existingQuestions as $i => $q)
            @include('materials._question_row', ['index' => $i, 'q' => $q])
        @endforeach
    </div>

    <template id="questionRowTemplate">
        @include('materials._question_row', ['index' => '__INDEX__', 'q' => null])
    </template>
</div>

@push('scripts')
<script>
(function () {
    const typeSelect = document.getElementById('type');
    const builder = document.getElementById('questionsBuilder');
    const list = document.getElementById('questionsList');
    const addBtn = document.getElementById('addQuestionBtn');
    const template = document.getElementById('questionRowTemplate');

    if (!typeSelect || !builder || !list || !addBtn || !template) return;

    let nextIndex = list.querySelectorAll('.question-row').length;

    function renumber() {
        list.querySelectorAll('.question-row').forEach((row, i) => {
            const label = row.querySelector('.question-row-label');
            if (label) label.textContent = 'Question ' + (i + 1);
        });
    }

    function addQuestion() {
        const html = template.innerHTML.replace(/__INDEX__/g, nextIndex);
        const wrapper = document.createElement('div');
        wrapper.innerHTML = html.trim();
        list.appendChild(wrapper.firstElementChild);
        nextIndex++;
        renumber();
    }

    function toggleBuilder() {
        const isComprehension = typeSelect.value === 'comprehension';
        builder.classList.toggle('d-none', !isComprehension);

        // Inputs inside a hidden builder must not block submit or post stale data.
        list.querySelectorAll('input, select').forEach(el => { el.disabled = !isComprehension; });

        if (isComprehension && list.children.length === 0) addQuestion();
    }

    list.addEventListener('click', function (e) {
        const btn = e.target.closest('.remove-question');
        if (!btn) return;
        btn.closest('.question-row').remove();
        renumber();
    });

    typeSelect.addEventListener('change', toggleBuilder);
    addBtn.addEventListener('click', addQuestion);

    renumber();
    toggleBuilder();
})();
</script>
@endpush
