@extends('layouts.app')

@section('title', 'Check this batch')

@section('content')
    <x-page-header title="Check this batch" icon="bi-clipboard-check"
                   :back="route('assessments.index')" back-label="Assessments"
                   subtitle="Nothing has been created yet. Look this over, then create the batch." />

    @php
        $types = ['oral_reading' => 'Oral Reading', 'comprehension' => 'Comprehension', 'combined' => 'Combined'];
        $languages = ['en' => 'English', 'fil' => 'Filipino'];

        // Two grades, not one. A child reading one grade off their own is the
        // ordinary case - it is what the old ±1 cap quietly permitted, and
        // calling it out would teach the teacher to ignore the marking. Two or
        // more is a different statement about that child: still legitimate, and
        // still hers to make, but worth her eye before fifteen readings start.
        $flagFrom = 2;
        $flagged = collect($rows)->filter(fn ($row) => abs($row['gap']) >= $flagFrom)->count();
    @endphp

    @if($flagged > 0)
        <div class="alert alert-warning d-flex gap-2 py-2 small" role="alert">
            <i class="bi bi-exclamation-triangle-fill mt-1"></i>
            <div>
                {{ ($flagged === 1 ? 'One child is' : $flagged . ' children are') . ' set to read ' . $flagFrom . ' or more grades from their own grade.' }}
                That is often exactly the point of a reading assessment, so nothing is stopping you - just
                check below that it is what you meant.
            </div>
        </div>
    @endif

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-sm-6 col-lg-3">
                    <div class="text-muted small">Children</div>
                    <div class="fs-5 fw-semibold">{{ count($rows) }} {{ count($rows) === 1 ? 'child' : 'children' }}</div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="text-muted small">Batch name</div>
                    <div class="fw-semibold">{{ $input['title'] ?? 'Untitled' }}</div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="text-muted small">Language &amp; type</div>
                    <div class="fw-semibold">{{ $languages[$input['language']] ?? $input['language'] }} &middot; {{ $types[$input['assessment_type']] ?? $input['assessment_type'] }}</div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="text-muted small">Batch material</div>
                    <div class="fw-semibold">
                        {{ $defaultMaterial->title }}
                        <span class="badge text-bg-light">Grade {{ $defaultMaterial->grade_level }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white"><strong>Who reads what</strong></div>
        <ol class="list-group list-group-flush list-group-numbered">
            @foreach($rows as $row)
                <li class="list-group-item d-flex flex-column flex-md-row gap-1 gap-md-3 justify-content-between">
                    <span class="fw-semibold">
                        {{ $row['learner']->full_name }}
                        <span class="text-muted small fw-normal ms-md-2">Grade {{ $row['learner']->grade_level }}</span>
                    </span>
                    <span class="text-md-end">
                        {{ $row['material']->title }}
                        <span class="badge text-bg-light">Grade {{ $row['material']->grade_level }}</span>
                        @if($row['override'])
                            <span class="badge text-bg-info-subtle text-info-emphasis">their own reading</span>
                        @endif
                        @if($row['gap'] !== 0)
                            @php
                                $big = abs($row['gap']) >= $flagFrom;
                                $grades = abs($row['gap']);
                                // Built as one string, not laid out across lines:
                                // a sentence broken by template whitespace is a
                                // sentence nobody can assert on.
                                $gapLine = $row['learner']->first_name . ' (Grade ' . $row['learner']->grade_level
                                    . ') will read a Grade ' . $row['material']->grade_level . ' passage - '
                                    . $grades . ($grades === 1 ? ' grade ' : ' grades ')
                                    . ($row['gap'] < 0 ? 'below' : 'above') . ' their own grade.';
                            @endphp
                            {{-- The gap in words, per child: the one thing a mis-tap
                                 can be caught by without reading the whole roster. --}}
                            <div class="small mt-1 {{ $big ? 'text-warning-emphasis fw-semibold' : 'text-muted' }}">
                                @if($big)<i class="bi bi-exclamation-triangle-fill me-1"></i>@endif{{ $gapLine }}
                            </div>
                        @endif
                    </span>
                </li>
            @endforeach
        </ol>
    </div>

    <form method="POST" action="{{ route('assessments.batch.store') }}" class="mt-3 d-flex flex-wrap gap-2 align-items-center">
        @csrf
        <input type="hidden" name="title" value="{{ $input['title'] }}">
        <input type="hidden" name="language" value="{{ $input['language'] }}">
        <input type="hidden" name="assessment_type" value="{{ $input['assessment_type'] }}">
        <input type="hidden" name="default_material_id" value="{{ $input['default_material_id'] }}">
        @foreach($rows as $row)
            <input type="hidden" name="entries[{{ $row['key'] }}][learner_id]" value="{{ $input['entries'][$row['key']]['learner_id'] }}">
            <input type="hidden" name="entries[{{ $row['key'] }}][material_id]" value="{{ $input['entries'][$row['key']]['material_id'] }}">
        @endforeach

        <button type="submit" class="btn btn-primary">
            <i class="bi bi-play-circle me-1"></i> Create batch
        </button>
        {{-- The same fields go back to the summary step, which hands them to the
             form as old input - so changing her mind costs her no ticks. --}}
        <button type="submit" class="btn btn-outline-secondary" name="edit" value="1"
                formaction="{{ route('assessments.batch.review') }}">
            <i class="bi bi-pencil me-1"></i> Change selection
        </button>
    </form>

    {{-- Its own form, outside the one above: Cancel must not carry the selection
         anywhere, and a link styled as faint text sat unnoticed beside two real
         buttons. Nothing has been created yet, so this discards the ticks and
         nothing else - which is exactly what it says, because a teacher who
         loses fifteen ticks to an unlabelled click will not trust the screen
         again. --}}
    <form method="GET" action="{{ route('assessments.batch.index') }}" class="mt-2">
        <button type="submit" class="btn btn-outline-danger"
                data-confirm="Discard this batch and lose the children you ticked? Nothing has been created yet.">
            <i class="bi bi-x-circle me-1"></i> Cancel and discard
        </button>
    </form>

    <script>
        // Not an inline onsubmit: the message is read from a data attribute, which
        // Blade escapes correctly, so no name or title can ever break the handler.
        document.querySelectorAll('[data-confirm]').forEach(function (button) {
            button.addEventListener('click', function (event) {
                if (!window.confirm(button.dataset.confirm)) event.preventDefault();
            });
        });
    </script>
@endsection
