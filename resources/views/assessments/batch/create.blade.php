@extends('layouts.app')

@section('title', 'Group Assessment')

@section('content')
    <x-page-header title="Group Assessment" icon="bi-people"
                   :back="route('assessments.index')" back-label="Assessments"
                   subtitle="Tick at least {{ $minLearners }} children and at most {{ $maxLearners }}. Everyone reads the batch material unless you give a child their own." />

    @php
        $oldDefault = old('default_material_id');
        $oldDefaultMaterial = filled($oldDefault) ? $materials->firstWhere('id', (int) $oldDefault) : null;
        $pickedCount = collect(old('entries', []))->filter(fn ($row) => is_array($row) && filled($row['learner_id'] ?? null))->count();
    @endphp

    <form method="POST" action="{{ route('assessments.batch.review') }}" id="batchSetup">
        @csrf

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="title" class="form-label">Batch name <span class="text-muted">(optional)</span></label>
                        <input type="text" class="form-control" id="title" name="title" maxlength="255"
                               value="{{ old('title') }}" placeholder="e.g. Morning group">
                    </div>

                    <div class="col-md-6">
                        <label for="language" class="form-label">Language <span class="text-danger">*</span></label>
                        <select class="form-select @error('language') is-invalid @enderror" id="language" name="language" required>
                            <option value="">Select a language...</option>
                            <option value="en" @selected(old('language') === 'en')>English</option>
                            <option value="fil" @selected(old('language') === 'fil')>Filipino</option>
                        </select>
                        @error('language') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-6">
                        <label for="assessment_type" class="form-label">Assessment Type <span class="text-danger">*</span></label>
                        <select class="form-select @error('assessment_type') is-invalid @enderror" id="assessment_type" name="assessment_type" required>
                            <option value="">Select a type...</option>
                            <option value="oral_reading" @selected(old('assessment_type') === 'oral_reading')>Oral Reading</option>
                            <option value="comprehension" @selected(old('assessment_type') === 'comprehension')>Comprehension</option>
                            <option value="combined" @selected(old('assessment_type') === 'combined')>Combined</option>
                        </select>
                        @error('assessment_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text">Comprehension / Combined only list materials with comprehension questions.</div>
                    </div>

                    <div class="col-md-6">
                        <label for="default_grade" class="form-label">Material grade</label>
                        <select class="form-select" id="default_grade" data-default="{{ $defaultGrade }}">
                            <option value="">Choose a grade...</option>
                            @foreach($gradeNames as $grade => $name)
                                <option value="{{ $grade }}" @selected($defaultGrade === (int) $grade)>{{ $name }}</option>
                            @endforeach
                        </select>
                        @if($defaultGrade !== null)
                            <div class="form-text">Your children are in {{ $gradeSpan }}, so that is the grade this batch reads. Change it if you need to.</div>
                        @else
                            <div class="form-text text-warning-emphasis">
                                Your children are in {{ $gradeSpan }}. Choose which grade this batch reads, then give anyone from the other grades their own reading below.
                            </div>
                        @endif
                        <div class="form-text text-warning-emphasis d-none" id="gradeSpanNote"></div>
                    </div>

                    <div class="col-12">
                        <label for="default_material_id" class="form-label">Batch reading material <span class="text-danger">*</span></label>
                        <select class="form-select @error('default_material_id') is-invalid @enderror"
                                id="default_material_id" name="default_material_id" required>
                            <option value="">Select a language and type first...</option>
                            @if($oldDefaultMaterial)
                                <option value="{{ $oldDefaultMaterial->id }}" selected>{{ $oldDefaultMaterial->title }} (Grade {{ $oldDefaultMaterial->grade_level }})</option>
                            @endif
                        </select>
                        @error('default_material_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                <strong>Children</strong>
                <span class="text-muted small" id="rosterCount">{{ $pickedCount }} of {{ $maxLearners }} ticked</span>
            </div>

            @error('entries') <div class="alert alert-danger py-2 m-3 mb-0">{{ $message }}</div> @enderror

            @if($groups === [])
                <div class="card-body text-muted">No children are assigned to you yet.</div>
            @else
                <div class="list-group list-group-flush" id="roster">
                    @foreach($groups as $group)
                        @if($showGroupLabels)
                            <div class="list-group-item bg-light fw-semibold small text-uppercase text-muted">{{ $group['label'] }}</div>
                        @endif
                        @foreach($group['learners'] as $learner)
                            @include('assessments.batch._entry', ['learner' => $learner, 'materials' => $materials])
                        @endforeach
                    @endforeach
                </div>
            @endif
        </div>

        <div class="position-sticky bottom-0 bg-body border-top mt-3 py-3 d-flex flex-wrap gap-2 align-items-center" style="z-index: 5">
            <button type="submit" class="btn btn-primary" id="reviewBtn">
                <i class="bi bi-arrow-right-circle me-1"></i> Review batch
            </button>
            <a href="{{ route('assessments.index') }}" class="btn btn-outline-secondary">Cancel</a>
            <span class="text-muted small ms-auto" id="rosterHint"></span>
        </div>
    </form>

    {{-- Every material the teacher may pick, loaded once with the page. The
         browser filters this list; the view itself never queries. --}}
    <template id="materialOptions">
        @foreach($materials as $material)
            <option value="{{ $material->id }}" data-grade="{{ $material->grade_level }}"
                    data-language="{{ $material->language }}"
                    data-questions="{{ $material->comprehension_questions_count > 0 ? 1 : 0 }}">{{ $material->title }} (Grade {{ $material->grade_level }}, {{ $material->word_count }} words)</option>
        @endforeach
    </template>

    <style>
        /* The tick is the whole interaction on this page, so it is sized to be
           seen and hit on a phone rather than to match a form checkbox. */
        .roster-check .form-check-input.roster-pick {
            width: 1.6rem; height: 1.6rem; margin-top: .1rem;
            border: 2px solid #6c757d; cursor: pointer;
        }
        .roster-check .form-check-input.roster-pick:checked { border-color: #0d6efd; }
        .roster-check .form-check-input.roster-pick:hover:not(:disabled) { border-color: #0d6efd; }
        .roster-check { padding-left: 2.2rem; }
        .roster-check .form-check-input.roster-pick { margin-left: -2.2rem; }
        .roster-check .form-check-label { padding-left: .5rem; cursor: pointer; }
        /* The row a teacher has ticked should be obvious down a list of fifteen. */
        [data-role="row"]:has(.roster-pick:checked) { background-color: #f0f6ff; }
        .roster-material { width: 100%; }
        @media (min-width: 768px) { .roster-material { width: 22rem; flex: 0 0 auto; } }
    </style>

<script>
(function () {
    const MIN = {{ $minLearners }};
    const MAX = {{ $maxLearners }};
    const form = document.getElementById('batchSetup');
    const roster = document.getElementById('roster');
    const tpl = document.getElementById('materialOptions');
    const langSel = document.getElementById('language');
    const typeSel = document.getElementById('assessment_type');
    const gradeSel = document.getElementById('default_grade');
    const defSel = document.getElementById('default_material_id');
    const countEl = document.getElementById('rosterCount');
    const hintEl = document.getElementById('rosterHint');
    const spanNote = document.getElementById('gradeSpanNote');
    const reviewBtn = document.getElementById('reviewBtn');

    if (!roster) return;

    const rows = Array.from(roster.querySelectorAll('[data-role="row"]'));
    let gradeTouched = false;

    function needsQuestions() {
        return typeSel.value === 'comprehension' || typeSel.value === 'combined';
    }

    /** The materials a teacher may choose right now, as fresh <option> nodes. */
    function options(grade) {
        const out = [];
        tpl.content.querySelectorAll('option').forEach(function (opt) {
            if (langSel.value && opt.dataset.language !== langSel.value) return;
            if (needsQuestions() && opt.dataset.questions !== '1') return;
            if (grade && opt.dataset.grade !== String(grade)) return;
            out.push(opt.cloneNode(true));
        });
        return out;
    }

    function repopulate(select, placeholder, grade) {
        const keep = select.value;
        select.innerHTML = '';
        const first = document.createElement('option');
        first.value = '';
        first.textContent = placeholder;
        select.appendChild(first);
        options(grade).forEach(function (opt) { select.appendChild(opt); });
        select.value = keep;
        if (select.value !== keep) select.value = '';
    }

    function loadDefault() {
        if (!langSel.value || !typeSel.value) {
            repopulate(defSel, 'Select a language and type first...', null);
            return;
        }
        if (!gradeSel.value) {
            repopulate(defSel, 'Choose a material grade first...', null);
            return;
        }
        repopulate(defSel, 'Choose a material...', gradeSel.value);
        if (defSel.options.length === 1) defSel.options[0].textContent = 'No materials for this grade yet';
    }

    /** A child's own reading may come from any grade - choosing it is the deliberate act. */
    function loadOverride(row) {
        const select = row.querySelector('[data-role="material"]');
        if (!langSel.value || !typeSel.value) return;
        repopulate(select, 'Choose a reading...', null);
    }

    /**
     * Write the batch material's name into every row that is not overriding, so
     * a row never names a passage she has since changed. Read from the selected
     * option rather than kept in a second variable: one source of truth.
     */
    function syncBatchReading() {
        const chosen = defSel.options[defSel.selectedIndex];
        const label = (defSel.value && chosen) ? chosen.textContent : 'no batch material chosen yet';

        rows.forEach(function (row) {
            row.querySelector('[data-role="batch-reading-title"]').textContent = label;
        });
    }

    /**
     * The override tick governs the dropdown: hidden and not submitted while it
     * is off, so an untouched row sends no material_id and the builder gives the
     * child the batch material. Turning it off clears the value as well as
     * hiding it - a hidden control that still submits is a lie.
     */
    function applyOverride(row) {
        const on = row.querySelector('[data-role="override"]').checked;
        const select = row.querySelector('[data-role="material"]');
        const stated = row.querySelector('[data-role="batch-reading"]');

        select.classList.toggle('d-none', !on);
        select.disabled = !on;
        stated.classList.toggle('d-none', on);

        if (on) {
            loadOverride(row);
        } else {
            select.value = '';
        }
    }

    function picked() {
        return rows.filter(function (row) { return row.querySelector('[data-role="pick"]').checked; });
    }

    function refreshSelection() {
        const chosen = picked();
        const grades = Array.from(new Set(chosen.map(function (row) { return row.dataset.grade; }))).sort();

        rows.forEach(function (row) {
            const box = row.querySelector('[data-role="pick"]');
            const over = row.querySelector('[data-role="override"]');

            // The override offer belongs only to a child who is in the batch.
            // Unticking a child takes her override with it, so a row cannot
            // keep a reading for a child who is no longer being assessed.
            row.querySelector('[data-role="override-wrap"]').classList.toggle('d-none', !box.checked);
            if (!box.checked && over.checked) over.checked = false;
            applyOverride(row);
            row.querySelector('[data-role="batch-reading"]').classList.toggle('d-none', !box.checked || over.checked);

            // A full roster still lets her untick, just not tick a sixteenth.
            box.disabled = !box.checked && chosen.length >= MAX;
        });

        countEl.textContent = chosen.length + ' of ' + MAX + ' ticked';

        if (grades.length === 1 && !gradeTouched && gradeSel.value !== grades[0]) {
            gradeSel.value = grades[0];
            loadDefault();
        }

        if (grades.length > 1) {
            spanNote.textContent = 'Your ticked children are in ' + grades.map(function (g) { return 'Grade ' + g; }).join(' and ')
                + '. Choose which grade the batch reads, then give the others their own reading.';
            spanNote.classList.remove('d-none');
        } else {
            spanNote.classList.add('d-none');
        }

        const short = chosen.length < MIN;
        reviewBtn.disabled = short;
        hintEl.textContent = short ? 'Tick at least ' + MIN + ' children.' : '';
    }

    rows.forEach(function (row) {
        row.querySelector('[data-role="pick"]').addEventListener('change', refreshSelection);
        row.querySelector('[data-role="override"]').addEventListener('change', function () {
            applyOverride(row);
        });
    });

    [langSel, typeSel].forEach(function (el) {
        el.addEventListener('change', function () {
            loadDefault();
            syncBatchReading();
            picked().forEach(function (row) {
                if (row.querySelector('[data-role="override"]').checked) loadOverride(row);
            });
        });
    });
    gradeSel.addEventListener('change', function () { gradeTouched = true; loadDefault(); syncBatchReading(); });
    defSel.addEventListener('change', syncBatchReading);

    form.addEventListener('submit', function (event) {
        if (picked().length < MIN) event.preventDefault();
    });

    loadDefault();
    syncBatchReading();
    refreshSelection();
})();
</script>
@endsection
