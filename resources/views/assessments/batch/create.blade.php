@extends('layouts.app')

@section('title', 'Group Assessment')

@section('content')
    <x-page-header title="Group Assessment" icon="bi-people"
                   :back="route('assessments.index')" back-label="Assessments"
                   subtitle="Set up one batch for up to {{ $maxLearners }} children." />

    @php
        $rows = old('entries');
        $rows = is_array($rows) && count($rows) > 0 ? array_values($rows) : [[]];
        $gradeNames = config('bigkas.grade_levels', []);
    @endphp

    <form method="POST" action="{{ route('assessments.batch.store') }}" id="batchSetup">
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
                        <label for="school_class_id" class="form-label">Class <span class="text-muted">(optional)</span></label>
                        <select class="form-select @error('school_class_id') is-invalid @enderror" id="school_class_id" name="school_class_id">
                            <option value="">No class</option>
                            @foreach($classes as $class)
                                <option value="{{ $class->id }}" {{ (string) old('school_class_id') === (string) $class->id ? 'selected' : '' }}>
                                    Grade {{ $class->grade_level }} - {{ $class->section }}
                                </option>
                            @endforeach
                        </select>
                        @error('school_class_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-6">
                        <label for="language" class="form-label">Language <span class="text-danger">*</span></label>
                        <select class="form-select @error('language') is-invalid @enderror" id="language" name="language" required>
                            <option value="">Select a language...</option>
                            <option value="en" {{ old('language') === 'en' ? 'selected' : '' }}>English</option>
                            <option value="fil" {{ old('language') === 'fil' ? 'selected' : '' }}>Filipino</option>
                        </select>
                        @error('language') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-6">
                        <label for="assessment_type" class="form-label">Assessment Type <span class="text-danger">*</span></label>
                        <select class="form-select @error('assessment_type') is-invalid @enderror" id="assessment_type" name="assessment_type" required>
                            <option value="">Select a type...</option>
                            <option value="oral_reading" {{ old('assessment_type') === 'oral_reading' ? 'selected' : '' }}>Oral Reading</option>
                            <option value="comprehension" {{ old('assessment_type') === 'comprehension' ? 'selected' : '' }}>Comprehension</option>
                            <option value="combined" {{ old('assessment_type') === 'combined' ? 'selected' : '' }}>Combined</option>
                        </select>
                        @error('assessment_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text">Comprehension / Combined only list materials with comprehension questions.</div>
                    </div>

                    <div class="col-md-4">
                        <label for="default_grade" class="form-label">Material grade</label>
                        <select class="form-select" id="default_grade">
                            @foreach($gradeNames as $grade => $name)
                                <option value="{{ $grade }}" {{ $grade == 4 ? 'selected' : '' }}>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-8">
                        <label for="default_material_id" class="form-label">Default reading material <span class="text-danger">*</span></label>
                        <select class="form-select" id="default_material_id" name="default_material_id" required disabled
                                data-old="{{ old('default_material_id') }}">
                            <option value="">Select a language and type first...</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <strong>Children <span class="text-muted" id="rosterCount"></span></strong>
                <button type="button" class="btn btn-outline-primary btn-sm" id="addEntry">
                    <i class="bi bi-plus-lg me-1"></i>Add child
                </button>
            </div>
            <div class="card-body">
                @error('entries') <div class="alert alert-danger py-2">{{ $message }}</div> @enderror

                <div id="roster">
                    @foreach($rows as $i => $row)
                        @include('assessments.batch._entry', ['i' => $i, 'row' => $row, 'learners' => $learners])
                    @endforeach
                </div>
            </div>
        </div>

        <div class="mt-4">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-play-circle me-1"></i> Create Batch
            </button>
            <a href="{{ route('assessments.index') }}" class="btn btn-outline-secondary ms-2">Cancel</a>
        </div>
    </form>

    <template id="entryTemplate">
        @include('assessments.batch._entry', ['i' => '__INDEX__', 'row' => [], 'learners' => $learners])
    </template>

<script>
(function () {
    const MAX = {{ $maxLearners }};
    const optionsUrl = "{{ route('materials.options') }}";
    const roster = document.getElementById('roster');
    const tpl = document.getElementById('entryTemplate');
    const addBtn = document.getElementById('addEntry');
    const count = document.getElementById('rosterCount');
    const langSel = document.getElementById('language');
    const typeSel = document.getElementById('assessment_type');
    const defGrade = document.getElementById('default_grade');
    const defSel = document.getElementById('default_material_id');

    async function fetchOptions(grade) {
        const params = new URLSearchParams({ grade_level: grade, language: langSel.value, assessment_type: typeSel.value });
        const res = await fetch(optionsUrl + '?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const json = await res.json();
        return { exact: (json.data && json.data.exact) || [], fallback: (json.data && json.data.fallback) || [] };
    }

    function fill(select, placeholder, data, keep) {
        select.innerHTML = '';
        const p = document.createElement('option');
        p.value = '';
        p.textContent = placeholder;
        select.appendChild(p);
        [['exact', 'Exact grade'], ['fallback', 'Other grades']].forEach(function (pair) {
            const list = data[pair[0]];
            if (!list.length) return;
            const group = document.createElement('optgroup');
            group.label = pair[1];
            list.forEach(function (m) {
                const o = document.createElement('option');
                o.value = m.id;
                o.textContent = (pair[0] === 'fallback' ? 'Grade ' + m.grade_level + ' - ' : '') + m.title + ' (' + m.word_count + ' words)';
                group.appendChild(o);
            });
            select.appendChild(group);
        });
        if (keep) select.value = keep;
        select.disabled = false;
    }

    function ready() { return langSel.value && typeSel.value; }

    async function loadDefault() {
        if (!ready()) {
            defSel.innerHTML = '<option value="">Select a language and type first...</option>';
            defSel.disabled = true;
            return;
        }
        defSel.disabled = true;
        try {
            const data = await fetchOptions(defGrade.value);
            const keep = defSel.value || defSel.dataset.old || '';
            fill(defSel, data.exact.length || data.fallback.length ? 'Choose a material...' : 'No materials found', data, keep);
        } catch (e) {
            defSel.innerHTML = '<option value="">Could not load materials. Try again.</option>';
        }
    }

    async function loadOverride(row) {
        const sel = row.querySelector('[data-role="material"]');
        const learner = row.querySelector('[data-role="learner"]').selectedOptions[0];
        const grade = learner && learner.dataset.grade;
        const keep = sel.value || sel.dataset.old || '';
        if (!grade || !ready()) {
            return;
        }
        try {
            fill(sel, 'Use the default material', await fetchOptions(grade), keep);
        } catch (e) { /* keep whatever is there */ }
    }

    function reindex() {
        // Names are rewritten to 0..n-1 in page order so the server's per-entry
        // error keys ("entries.N.*") always line up with the rows shown.
        const rows = roster.querySelectorAll('[data-role="entry"]');
        rows.forEach(function (row, i) {
            row.dataset.entry = i;
            row.querySelectorAll('[name]').forEach(function (el) {
                el.name = el.name.replace(/entries\[[^\]]*\]/, 'entries[' + i + ']');
            });
            const box = row.querySelector('input[type="checkbox"]');
            const label = row.querySelector('label.form-check-label');
            box.id = 'fallback-' + i;
            label.htmlFor = box.id;
            row.querySelector('[data-role="remove"]').classList.toggle('d-none', rows.length === 1);
        });
        count.textContent = '(' + rows.length + ' of ' + MAX + ')';
        addBtn.disabled = rows.length >= MAX;
    }

    function wire(row) {
        row.querySelector('[data-role="learner"]').addEventListener('change', function () {
            row.querySelector('[data-role="material"]').dataset.old = '';
            loadOverride(row);
        });
        row.querySelector('[data-role="remove"]').addEventListener('click', function () {
            row.remove();
            reindex();
        });
    }

    addBtn.addEventListener('click', function () {
        if (roster.querySelectorAll('[data-role="entry"]').length >= MAX) return;
        const node = tpl.content.firstElementChild.cloneNode(true);
        roster.appendChild(node);
        reindex();
        wire(node);
    });

    function refreshAll() {
        loadDefault();
        roster.querySelectorAll('[data-role="entry"]').forEach(loadOverride);
    }
    langSel.addEventListener('change', refreshAll);
    typeSel.addEventListener('change', refreshAll);
    defGrade.addEventListener('change', function () { defSel.dataset.old = ''; defSel.value = ''; loadDefault(); });

    roster.querySelectorAll('[data-role="entry"]').forEach(wire);
    reindex();
    refreshAll();
})();
</script>
@endsection
