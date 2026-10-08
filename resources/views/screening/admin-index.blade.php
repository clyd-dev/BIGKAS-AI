@extends('layouts.app')

@section('title', 'Group Screening Test')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-ui-checks-grid me-2"></i>Group Screening Test</h4>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('screening.index') }}" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">School Year</label>
                    <select name="school_year" class="form-select form-select-sm">
                        @foreach($schoolYears as $sy)
                            <option value="{{ $sy }}" {{ $schoolYear === $sy ? 'selected' : '' }}>{{ $sy }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Period</label>
                    <select name="period" class="form-select form-select-sm">
                        @foreach($periods as $key => $label)
                            <option value="{{ $key }}" {{ $period === $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Test language</label>
                    <select name="language" class="form-select form-select-sm">
                        @foreach($languages as $key => $label)
                            <option value="{{ $key }}" {{ $language === $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-search me-1"></i> Filter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Grade &amp; Section</th>
                            <th>Teacher</th>
                            <th class="text-center">Enrolment</th>
                            <th class="text-center">Score ≥ 14</th>
                            <th class="text-center">Score &lt; 14</th>
                            <th class="text-center">Not tested</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($classes as $class)
                            <tr>
                                <td class="fw-semibold">Grade {{ $class->grade_level }} – {{ $class->section }}</td>
                                <td>{{ $class->teacher?->name ?? '—' }}</td>
                                <td class="text-center">{{ $class->gst['enrolment'] }}</td>
                                <td class="text-center text-success fw-semibold">{{ $class->gst['at_grade'] }}</td>
                                <td class="text-center text-danger fw-semibold">{{ $class->gst['below'] }}</td>
                                <td class="text-center text-muted">{{ $class->gst['not_tested'] }}</td>
                                <td class="text-end">
                                    <a href="{{ route('screening.section', [$class, 'period' => $period, 'language' => $language]) }}" class="btn btn-sm btn-primary px-3">
                                        <i class="bi bi-eye me-1"></i> View
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-4">No Grade 3–6 sections found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
        <div class="small text-muted">
            @if($classes->total() > 0)
                Showing {{ $classes->firstItem() }}–{{ $classes->lastItem() }} of {{ $classes->total() }}
            @endif
        </div>
        {{ $classes->onEachSide(1)->links('partials.pagination') }}
    </div>
@endsection
