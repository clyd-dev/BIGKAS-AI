@extends('layouts.app')

@section('title', 'Check this batch')

@section('content')
    <x-page-header title="Check this batch" icon="bi-clipboard-check"
                   :back="route('assessments.index')" back-label="Assessments"
                   subtitle="Nothing has been created yet. Look this over, then create the batch." />

    @php
        $types = ['oral_reading' => 'Oral Reading', 'comprehension' => 'Comprehension', 'combined' => 'Combined'];
        $languages = ['en' => 'English', 'fil' => 'Filipino'];
    @endphp

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
        <a href="{{ route('assessments.index') }}" class="btn btn-link text-muted">Cancel</a>
    </form>
@endsection
