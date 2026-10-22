@extends('layouts.app')

@section('title', 'Add a child')

@section('content')
    {{--
        The setup form's roster, on a board that is already running.

        The rows are assessments.batch._entry - the very partial the setup form
        uses - so there is one way of picking a child in this application: the
        same big tick, the same per-child reading behind a second tick, the same
        entries[<learner id>][...] names, the same error keys. Only the children
        already on this board are missing, which is what makes a child
        structurally impossible to add twice.

        What the setup form asks above its roster - language, assessment type,
        material grade, batch material - is not asked again. The batch answered
        all four, and an added child is assessed on exactly the terms the
        children beside them were. That is why this page's script is the
        selection half of the setup form's and nothing more: there are no
        selects here to drive.
    --}}
    <x-page-header title="Add a child" icon="bi-person-plus"
                   :back="route('assessments.batch.board', $batch)" back-label="Back to the board"
                   :subtitle="'Tick anyone who still needs a reading. You can add ' . $room . ' more to this batch.'" />

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-2 small">
                <div class="col-sm-6">
                    <span class="text-muted">This batch</span>
                    <div class="fw-semibold">{{ $batch->title ?: 'Group Assessment' }}</div>
                </div>
                <div class="col-sm-6">
                    <span class="text-muted">Everyone reads</span>
                    <div class="fw-semibold">
                        @if($defaultMaterial)
                            {{ $defaultMaterial->title }} <span class="text-muted">(Grade {{ $defaultMaterial->grade_level }})</span>
                        @else
                            <span class="text-muted">no batch material on this batch</span>
                        @endif
                    </div>
                </div>
                <div class="col-12 text-muted">
                    {{ $batch->language === 'fil' ? 'Filipino' : 'English' }},
                    {{ str_replace('_', ' ', $batch->assessment_type) }}.
                    A child who should read something else can be given their own reading below.
                </div>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('assessments.batch.add.store', $batch) }}" id="batchAdd">
        @csrf

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                <strong>Children not yet on this board</strong>
                <span class="text-muted small" id="rosterCount">0 of {{ $room }} ticked</span>
            </div>

            @error('entries') <div class="alert alert-danger py-2 m-3 mb-0">{{ $message }}</div> @enderror

            @if($groups === [])
                <div class="card-body text-muted">
                    Every child you can reach is already on this board.
                </div>
            @else
                <div class="list-group list-group-flush" id="roster">
                    @foreach($groups as $group)
                        @if($showGroupLabels)
                            <div class="list-group-item bg-light fw-semibold small text-uppercase text-muted">{{ $group['label'] }}</div>
                        @endif
                        @foreach($group['learners'] as $learner)
                            @include('assessments.batch._entry', [
                                'learner' => $learner,
                                'materials' => $materials,
                                'gradeNote' => $gradeNotes[$learner->id] ?? null,
                            ])
                        @endforeach
                    @endforeach
                </div>
            @endif
        </div>

        <div class="position-sticky bottom-0 bg-body border-top mt-3 py-3 d-flex flex-wrap gap-2 align-items-center" style="z-index: 5">
            <button type="submit" class="btn btn-primary" id="addBtn" disabled>
                <i class="bi bi-person-plus me-1"></i> Add to batch
            </button>
            <a href="{{ route('assessments.batch.board', $batch) }}" class="btn btn-outline-secondary">Cancel</a>
            <span class="text-muted small ms-auto" id="rosterHint">Tick at least one child.</span>
        </div>
    </form>

    {{-- Every reading an added child may be given, loaded once with the page and
         already narrowed to this batch's language and type by the controller.
         The view itself never queries (R39). --}}
    <template id="materialOptions">
        @foreach($materials as $material)
            <option value="{{ $material->id }}" data-grade="{{ $material->grade_level }}">{{ $material->title }} (Grade {{ $material->grade_level }}, {{ $material->word_count }} words)</option>
        @endforeach
    </template>

    <style>
        /* The same tick as the setup roster: sized to be seen and hit on a phone. */
        .roster-check .form-check-input.roster-pick {
            width: 1.6rem; height: 1.6rem; margin-top: .1rem;
            border: 2px solid #6c757d; cursor: pointer;
        }
        .roster-check .form-check-input.roster-pick:checked { border-color: #0d6efd; }
        .roster-check .form-check-input.roster-pick:hover:not(:disabled) { border-color: #0d6efd; }
        .roster-check { padding-left: 2.2rem; }
        .roster-check .form-check-input.roster-pick { margin-left: -2.2rem; }
        .roster-check .form-check-label { padding-left: .5rem; cursor: pointer; }
        [data-role="row"]:has(.roster-pick:checked) { background-color: #f0f6ff; }
        .roster-material { width: 100%; }
        @media (min-width: 768px) { .roster-material { width: 22rem; flex: 0 0 auto; } }
    </style>

<script>
(function () {
    // How many more children this batch may hold - the cap counts the children
    // already on the board, so this is never the cap itself.
    const ROOM = {{ $room }};
    const form = document.getElementById('batchAdd');
    const roster = document.getElementById('roster');
    const tpl = document.getElementById('materialOptions');
    const countEl = document.getElementById('rosterCount');
    const hintEl = document.getElementById('rosterHint');
    const addBtn = document.getElementById('addBtn');

    if (!roster) return;

    const rows = Array.from(roster.querySelectorAll('[data-role="row"]'));
    // Escaped for a script context, not merely encoded: a material title is
    // free text an admin typed, and it must never be able to end this tag.
    const batchReading = @json(
        $defaultMaterial ? $defaultMaterial->title . ' (Grade ' . $defaultMaterial->grade_level . ')' : 'the batch material',
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    );

    /**
     * The override tick governs the dropdown, exactly as it does on the setup
     * form: hidden and not submitted while it is off, so an untouched row sends
     * no material_id and the child is given the batch material. Turning it off
     * clears the value as well as hiding it - a hidden control that still
     * submits is a lie.
     */
    function applyOverride(row) {
        const on = row.querySelector('[data-role="override"]').checked;
        const select = row.querySelector('[data-role="material"]');
        const stated = row.querySelector('[data-role="batch-reading"]');

        select.classList.toggle('d-none', !on);
        select.disabled = !on;
        stated.classList.toggle('d-none', on);

        if (on) {
            fill(select);
        } else {
            select.value = '';
        }
    }

    /**
     * Any grade, on purpose: choosing a passage for one child IS the deliberate
     * act, and a cross-grade reading is permitted with its gap stated, never
     * forbidden. The list is already this batch's language and type.
     */
    function fill(select) {
        const keep = select.value;
        select.innerHTML = '';
        const first = document.createElement('option');
        first.value = '';
        first.textContent = 'Choose a reading...';
        select.appendChild(first);
        tpl.content.querySelectorAll('option').forEach(function (opt) {
            select.appendChild(opt.cloneNode(true));
        });
        select.value = keep;
        if (select.value !== keep) select.value = '';
    }

    function picked() {
        return rows.filter(function (row) { return row.querySelector('[data-role="pick"]').checked; });
    }

    function refreshSelection() {
        const chosen = picked();

        rows.forEach(function (row) {
            const box = row.querySelector('[data-role="pick"]');
            const over = row.querySelector('[data-role="override"]');

            // The override offer belongs only to a child who is being added.
            row.querySelector('[data-role="override-wrap"]').classList.toggle('d-none', !box.checked);
            if (!box.checked && over.checked) over.checked = false;
            applyOverride(row);

            // A full selection still lets her untick, just not tick one more.
            box.disabled = !box.checked && chosen.length >= ROOM;
        });

        countEl.textContent = chosen.length + ' of ' + ROOM + ' ticked';
        addBtn.disabled = chosen.length < 1;
        hintEl.textContent = chosen.length < 1 ? 'Tick at least one child.' : '';
    }

    rows.forEach(function (row) {
        // Named handlers, never an inline one: a child called O’Brien is copy
        // here, not code.
        row.querySelector('[data-role="pick"]').addEventListener('change', refreshSelection);
        row.querySelector('[data-role="override"]').addEventListener('change', function () {
            applyOverride(row);
        });
        row.querySelector('[data-role="batch-reading-title"]').textContent = batchReading;
    });

    form.addEventListener('submit', function (event) {
        if (picked().length < 1) event.preventDefault();
    });

    refreshSelection();
})();
</script>
@endsection
