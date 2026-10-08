@extends('layouts.app')

@section('title', 'New Assessment')

@section('content')
    <x-page-header title="New Assessment" icon="bi-plus-circle"
                   :back="route('assessments.index')" back-label="Assessments" />

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('assessments.store') }}" id="assessmentWizard">
                @csrf
                <input type="hidden" name="allow_fallback" id="allow_fallback" value="0">

                <div class="row g-3">
                    {{-- Step 1: Learner (fixes grade level) --}}
                    <div class="col-md-6">
                        <label for="learner_id" class="form-label"><span class="badge bg-primary me-1">1</span>Select Learner <span class="text-danger">*</span></label>
                        <select class="form-select @error('learner_id') is-invalid @enderror" id="learner_id" name="learner_id" required>
                            <option value="">Choose a learner...</option>
                            @foreach($learners as $learner)
                                <option value="{{ $learner->id }}" data-grade="{{ $learner->grade_level }}" {{ old('learner_id') == $learner->id ? 'selected' : '' }}>
                                    {{ $learner->full_name }} (Grade {{ $learner->grade_level }})
                                </option>
                            @endforeach
                        </select>
                        @error('learner_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- Step 2: Language (English / Filipino only) --}}
                    <div class="col-md-6">
                        <label for="language" class="form-label"><span class="badge bg-primary me-1">2</span>Language <span class="text-danger">*</span></label>
                        <select class="form-select" id="language" name="language" required disabled>
                            <option value="">Select a learner first...</option>
                            <option value="en" {{ old('language') === 'en' ? 'selected' : '' }}>English</option>
                            <option value="fil" {{ old('language') === 'fil' ? 'selected' : '' }}>Filipino</option>
                        </select>
                    </div>

                    {{-- Step 3: Assessment type (filters materials by questions) --}}
                    <div class="col-md-6">
                        <label for="assessment_type" class="form-label"><span class="badge bg-primary me-1">3</span>Assessment Type <span class="text-danger">*</span></label>
                        <select class="form-select" id="assessment_type" name="assessment_type" required disabled>
                            <option value="">Select a language first...</option>
                            <option value="oral_reading" {{ old('assessment_type') === 'oral_reading' ? 'selected' : '' }}>Oral Reading</option>
                            <option value="comprehension" {{ old('assessment_type') === 'comprehension' ? 'selected' : '' }}>Comprehension</option>
                            <option value="combined" {{ old('assessment_type') === 'combined' ? 'selected' : '' }}>Combined</option>
                        </select>
                        <div class="form-text">Comprehension / Combined only list materials with comprehension questions.</div>
                    </div>

                    {{-- Step 4: Material (last — filtered by all of the above) --}}
                    <div class="col-md-6">
                        <label for="material_id" class="form-label"><span class="badge bg-primary me-1">4</span>Reading Material <span class="text-danger">*</span></label>
                        <select class="form-select @error('material_id') is-invalid @enderror" id="material_id" name="material_id" required disabled>
                            <option value="">Select a type first...</option>
                        </select>
                        @error('material_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text" id="material_hint"></div>
                    </div>
                </div>

                <div class="mt-4">
                    <button type="submit" class="btn btn-primary" id="btnSubmit" disabled>
                        <i class="bi bi-play-circle me-1"></i> Create Assessment
                    </button>
                    <a href="{{ route('assessments.index') }}" class="btn btn-outline-secondary ms-2">Cancel</a>
                </div>
            </form>
        </div>
    </div>

<script>
(function () {
    const learnerSel = document.getElementById('learner_id');
    const langSel = document.getElementById('language');
    const typeSel = document.getElementById('assessment_type');
    const matSel = document.getElementById('material_id');
    const fallbackInput = document.getElementById('allow_fallback');
    const hint = document.getElementById('material_hint');
    const submitBtn = document.getElementById('btnSubmit');
    const optionsUrl = "{{ route('materials.options') }}";
    const oldMaterial = "{{ old('material_id') }}";

    function learnerGrade() {
        const opt = learnerSel.selectedOptions[0];
        return opt && opt.dataset.grade ? parseInt(opt.dataset.grade, 10) : null;
    }

    function resetMaterials(message) {
        matSel.innerHTML = '<option value="">' + message + '</option>';
        matSel.disabled = true;
        fallbackInput.value = '0';
        submitBtn.disabled = true;
        hint.textContent = '';
    }

    async function loadMaterials(preselectId) {
        const grade = learnerGrade();
        if (!grade || !langSel.value || !typeSel.value) {
            resetMaterials('Complete the steps above first...');
            return;
        }

        matSel.innerHTML = '<option value="">Loading materials...</option>';
        matSel.disabled = true;
        submitBtn.disabled = true;

        const params = new URLSearchParams({
            grade_level: grade,
            language: langSel.value,
            assessment_type: typeSel.value,
        });

        try {
            const res = await fetch(optionsUrl + '?' + params.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const json = await res.json();
            const exact = (json.data && json.data.exact) || [];
            const fallback = (json.data && json.data.fallback) || [];

            matSel.innerHTML = '';
            if (exact.length === 0 && fallback.length === 0) {
                resetMaterials('No materials found for this combination.');
                hint.textContent = 'No Grade ' + grade + ' materials match. Ask an admin to add some.';
                return;
            }

            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = 'Choose a material...';
            matSel.appendChild(placeholder);

            if (exact.length > 0) {
                const group = document.createElement('optgroup');
                group.label = 'Grade ' + grade;
                exact.forEach(function (m) {
                    const o = document.createElement('option');
                    o.value = m.id;
                    o.dataset.fallback = '0';
                    o.textContent = m.title + ' (' + m.word_count + ' words)';
                    group.appendChild(o);
                });
                matSel.appendChild(group);
                hint.textContent = exact.length + ' material(s) for Grade ' + grade + '.';
            }

            if (fallback.length > 0) {
                const group = document.createElement('optgroup');
                group.label = 'Other grades (fallback)';
                fallback.forEach(function (m) {
                    const o = document.createElement('option');
                    o.value = m.id;
                    o.dataset.fallback = '1';
                    o.textContent = 'Grade ' + m.grade_level + ' - ' + m.title + ' (' + m.word_count + ' words)';
                    group.appendChild(o);
                });
                matSel.appendChild(group);
                hint.textContent = (hint.textContent ? hint.textContent + ' ' : '') +
                    'No exact-grade match — showing adjacent grades instead.';
            }

            matSel.disabled = false;

            if (preselectId) {
                matSel.value = preselectId;
                syncFallback();
            }
        } catch (e) {
            resetMaterials('Could not load materials. Try again.');
        }
    }

    function syncFallback() {
        const opt = matSel.selectedOptions[0];
        fallbackInput.value = opt && opt.dataset.fallback === '1' ? '1' : '0';
        submitBtn.disabled = !matSel.value;
    }

    learnerSel.addEventListener('change', function () {
        langSel.disabled = !learnerSel.value;
        if (!learnerSel.value) {
            langSel.value = '';
            typeSel.value = '';
            typeSel.disabled = true;
            resetMaterials('Complete the steps above first...');
        }
    });

    langSel.addEventListener('change', function () {
        typeSel.disabled = !langSel.value;
        if (!langSel.value) {
            typeSel.value = '';
        }
        loadMaterials(oldMaterial || null);
    });

    typeSel.addEventListener('change', function () {
        loadMaterials(oldMaterial || null);
    });

    matSel.addEventListener('change', syncFallback);

    // Restore chained state after validation errors.
    if (learnerSel.value) {
        langSel.disabled = false;
        if (langSel.value) {
            typeSel.disabled = false;
            if (typeSel.value) {
                loadMaterials(oldMaterial || null);
            }
        }
    }
})();
</script>
@endsection
