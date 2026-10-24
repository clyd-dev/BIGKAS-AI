@extends('layouts.app')

@section('title', 'Learner Reading Master List')

@section('content')
    @php
        // A teacher's copy is pinned to their own section by the server, so the URL carries only year and language.
        $q = $mine ? array_filter(['school_year' => $schoolYear, 'language' => $language])
                   : array_filter(['school_year' => $schoolYear, 'grade' => $grade, 'class_id' => $classId, 'language' => $language]);
        $routePrefix = $mine ? 'learners.master-list' : 'reports.master-list';
    @endphp
    <x-page-header title="Learner Reading Master List" icon="bi-list-check"
        :subtitle="$mine ? 'Your section: every learner\'s reading levels, scores and progress' : 'Every learner\'s reading levels, scores and progress, by grade and section'">
        <x-slot:actions>
            @if($mine)
                <a href="{{ route('learners.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Learners</a>
            @endif
            @if($list['totals']['learners'] > 0)
                <a href="{{ route($routePrefix . '.print', $q) }}" target="_blank" class="btn btn-primary btn-sm"><i class="bi bi-printer me-1"></i> Print</a>
                <a href="{{ route($routePrefix . '.pdf', $q) }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-file-earmark-pdf me-1"></i> PDF</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <form method="GET" class="card border-0 shadow-sm mb-3"><div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1">School year</label>
                <select name="school_year" class="form-select form-select-sm" onchange="this.form.submit()">
                    @foreach($schoolYears as $y)<option value="{{ $y }}" @selected($y === $schoolYear)>{{ $y }}</option>@endforeach
                </select>
            </div>
            @unless($mine)
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Grade</label>
                <select name="grade" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All grades</option>
                    @foreach(range(1, 6) as $g)<option value="{{ $g }}" @selected($grade === $g)>Grade {{ $g }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1">Section</label>
                <select name="class_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All sections</option>
                    @foreach($classes->when($grade, fn ($c) => $c->where('grade_level', $grade)) as $c)
                        <option value="{{ $c->id }}" @selected($classId === $c->id)>Grade {{ $c->grade_level }} - {{ $c->section }}</option>
                    @endforeach
                </select>
            </div>
            @endunless
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Language</label>
                <select name="language" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="all" @selected($language === 'all')>English &amp; Filipino</option>
                    <option value="en" @selected($language === 'en')>English</option>
                    <option value="fil" @selected($language === 'fil')>Filipino</option>
                </select>
            </div>
            <div class="col-12 col-md-2 d-grid"><button class="btn btn-outline-secondary btn-sm">Apply</button></div>
        </div>
    </div></form>

    <div class="alert alert-secondary small py-2">
        <i class="bi bi-shield-lock me-1"></i> This list shows every child by name. Printing it is recorded in the activity log; keep printed copies confidential.
    </div>

    @include('reports.master-list._styles')
    @forelse($list['sections'] as $section)
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between gap-1">
                <h6 class="mb-0">{{ $section['label'] }}</h6>
                <small class="text-muted">Adviser: {{ $section['teacher'] ?: '-' }}</small>
            </div>
            <div class="card-body p-2">
                <div class="table-responsive">@include('reports.master-list._section')</div>
            </div>
        </div>
    @empty
        <div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5">No learners match this selection.</div></div>
    @endforelse
@endsection
