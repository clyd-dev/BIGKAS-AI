@extends('layouts.app')

@section('title', 'Phil-IRI Forms')

@section('content')
    @php
        $forms = [
            ['1A / 1B', 'Group Screening Test Class Record', 'Talaan ng Pangkatang Pagtatasa ng Klase (TPPK) / Screening Test Class Reading Record (STCRR)',
                'Subject teacher', 'School head', route('screening.index'), 'Open sections'],
            ['2', 'School Reading Profile (SRP)', 'Talaan ng Paaralan sa Pagbabasa (TPP)',
                'School head / reading coordinator', 'Used to build Form 5', route('reports.form2.index'), 'Open Form 2'],
            ['3A / 3B', 'Grade Level Passage Rating Sheet', 'Markahang Papel ng Panggradong Lebel na Teksto',
                'Teacher / test administrator', 'Kept at school; feeds Form 4', route('assessments.index'), 'Open learners'],
            ['4', 'Individual Summary Record (ISR)', 'Talaan ng Indibidwal na Pagbabasa (TIP)',
                'Teacher / test administrator', 'Kept at school; feeds Form 5', route('learners.index'), 'Open learners'],
        ];
    @endphp

    <x-page-header title="Phil-IRI Forms" icon="bi-journal-bookmark-fill">
        <x-slot:meta>
            <p class="pg-sub mb-0">
                <i class="bi bi-building me-1"></i>{{ $school?->name }}
                @if($schoolYear) <span class="ms-2"><i class="bi bi-calendar3 me-1"></i>S.Y. {{ $schoolYear }}</span> @endif
            </p>
        </x-slot:meta>
    </x-page-header>

    {{-- The official forms --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-files me-1"></i> The Phil-IRI forms</h6></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr><th>Form</th><th>Name</th><th>Accomplished by</th><th>Goes to</th><th class="text-end">Action</th></tr>
                    </thead>
                    <tbody>
                        @foreach($forms as [$no, $name, $fil, $by, $to, $url, $label])
                            <tr>
                                <td class="fw-bold text-nowrap">Form {{ $no }}</td>
                                <td>{{ $name }}<div class="small text-muted">{{ $fil }}</div></td>
                                <td>{{ $by }}</td>
                                <td>{{ $to }}</td>
                                <td class="text-end"><a href="{{ $url }}" class="btn btn-sm btn-primary px-3">{{ $label }}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white small text-muted">
            Form 3A/3B is opened from a learner's assessment history (&ldquo;Form 3&rdquo; button). Form 4 is opened from a learner's page.
            Form 5 (the school submission to DepEd) is not part of the system yet.
        </div>
    </div>

    {{-- Filters --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1">School Year</label>
                    <select name="school_year" class="form-select form-select-sm">
                        @foreach($schoolYears as $sy)
                            <option value="{{ $sy }}" {{ $schoolYear == $sy ? 'selected' : '' }}>S.Y. {{ $sy }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1">Grade Level</label>
                    <select name="grade_level" class="form-select form-select-sm">
                        <option value="">All Grades (3–6)</option>
                        @for($g = 3; $g <= 6; $g++)
                            <option value="{{ $g }}" {{ $grade == $g ? 'selected' : '' }}>Grade {{ $g }}</option>
                        @endfor
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-1">
                    <button class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-search me-1"></i> Filter</button>
                    <a href="{{ route('admin.phil-iri') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Section progress --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-diagram-3 me-1"></i> Progress by section</h6></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Grade &amp; Section</th>
                            <th>Teacher</th>
                            <th class="text-center">Learners</th>
                            <th class="text-center">Screened (GST)</th>
                            <th class="text-center">Assessed</th>
                            <th>Reading levels</th>
                            <th>Report</th>
                            <th class="text-end">Forms</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($sections as $s)
                            @php $assessed = $s->independent_count + $s->instructional_count + $s->frustration_count; @endphp
                            <tr>
                                <td class="fw-semibold">Grade {{ $s->grade_level }} – {{ $s->section }}</td>
                                <td>{{ $s->teacher?->name ?? '—' }}</td>
                                <td class="text-center">{{ $s->learners_count }}</td>
                                <td class="text-center">{{ $s->gst_count }} / {{ $s->learners_count }}</td>
                                <td class="text-center">{{ $s->assessed_count }}</td>
                                <td style="min-width: 140px;">
                                    @if($assessed > 0)
                                        <div class="progress" style="height: 8px;"
                                             title="Independent {{ $s->independent_count }} · Instructional {{ $s->instructional_count }} · Frustration {{ $s->frustration_count }}">
                                            <div class="progress-bar bg-success" style="width: {{ $s->independent_count / $assessed * 100 }}%"></div>
                                            <div class="progress-bar bg-warning" style="width: {{ $s->instructional_count / $assessed * 100 }}%"></div>
                                            <div class="progress-bar bg-danger" style="width: {{ $s->frustration_count / $assessed * 100 }}%"></div>
                                        </div>
                                    @else
                                        <span class="small text-muted">Not assessed</span>
                                    @endif
                                </td>
                                <td>@include('reports.submissions._status', ['status' => $reportStatus[$s->id] ?? null])</td>
                                <td class="text-end text-nowrap">
                                    <a href="{{ route('screening.section', [$s, 'language' => 'fil']) }}" class="btn btn-sm btn-outline-primary">1A/1B</a>
                                    <a href="{{ route('learners.index', ['class_id' => $s->id]) }}" class="btn btn-sm btn-outline-primary">Learners</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-4">No Grade 3–6 sections for this school year.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
        <div class="small text-muted">
            @if($sections->total() > 0)
                Showing {{ $sections->firstItem() }}–{{ $sections->lastItem() }} of {{ $sections->total() }}
            @endif
        </div>
        {{ $sections->onEachSide(1)->links('partials.pagination') }}
    </div>
@endsection
