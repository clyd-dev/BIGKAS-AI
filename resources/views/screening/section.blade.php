@extends('layouts.app')

@section('title', 'Screening Scores')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">
            <i class="bi bi-ui-checks-grid me-2"></i>Grade {{ $class->grade_level }} – {{ $class->section }}
            <span class="fs-6 text-muted">{{ $languages[$language] }} · {{ $periods[$period] }}</span>
        </h4>
        <a href="{{ route('screening.index', ['period' => $period, 'language' => $language, 'school_year' => $class->school_year]) }}"
           class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back</a>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('screening.section', $class) }}" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small">Period</label>
                    <select name="period" class="form-select form-select-sm" onchange="this.form.submit()">
                        @foreach($periods as $key => $label)
                            <option value="{{ $key }}" {{ $period === $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Test language</label>
                    <select name="language" class="form-select form-select-sm" onchange="this.form.submit()">
                        @foreach($languages as $key => $label)
                            <option value="{{ $key }}" {{ $language === $key ? 'selected' : '' }}>{{ $label }} (Form {{ $key === 'fil' ? '1A' : '1B' }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-5 d-flex gap-2 justify-content-md-end">
                    <a href="{{ route('screening.record.print', [$class, 'period' => $period, 'language' => $language]) }}" target="_blank" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-printer me-1"></i> Print Form {{ $language === 'fil' ? '1A' : '1B' }}
                    </a>
                    <a href="{{ route('screening.record.pdf', [$class, 'period' => $period, 'language' => $language]) }}" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-file-earmark-pdf me-1"></i> PDF
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        @foreach([['Enrolment', $summary['enrolment'], 'primary'], ['Score ≥ 14', $summary['at_grade'], 'success'], ['Score < 14', $summary['below'], 'danger'], ['Not tested', $summary['not_tested'], 'secondary']] as [$label, $value, $color])
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm text-center"><div class="card-body">
                    <h3 class="text-{{ $color }} mb-0">{{ $value }}</h3><small class="text-muted">{{ $label }}</small>
                </div></div>
            </div>
        @endforeach
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Learner</th>
                            <th class="text-center">Literal</th>
                            <th class="text-center">Inferential</th>
                            <th class="text-center">Critical</th>
                            <th class="text-center">Total</th>
                            <th>Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($learners as $learner)
                            @php $r = $learner->gstResults->first(); @endphp
                            <tr>
                                <td class="fw-semibold">{{ $learner->getFullName() }}</td>
                                @if($r && $r->test_taken)
                                    <td class="text-center">{{ $r->literal_correct ?? '—' }}</td>
                                    <td class="text-center">{{ $r->inferential_correct ?? '—' }}</td>
                                    <td class="text-center">{{ $r->critical_correct ?? '—' }}</td>
                                    <td class="text-center fw-semibold">{{ $r->total_score ?? '—' }}</td>
                                    <td>
                                        @if($r->classification === 'at_grade_level')
                                            <span class="badge bg-success">At grade level</span>
                                        @elseif($r->classification === 'needs_assessment')
                                            <span class="badge bg-warning text-dark">Oral assessment</span>
                                            <span class="small text-muted">start: {{ \App\Services\GstScoring::startingLevelLabel($r->starting_level) }}</span>
                                        @endif
                                    </td>
                                @else
                                    <td colspan="4" class="text-center text-muted">—</td>
                                    <td><span class="badge bg-secondary">{{ $r ? 'Absent' : 'Not encoded' }}</span></td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">No learners in this section.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
        <div class="small text-muted">
            @if($learners->total() > 0)
                Showing {{ $learners->firstItem() }}–{{ $learners->lastItem() }} of {{ $learners->total() }}
            @endif
        </div>
        {{ $learners->onEachSide(1)->links('partials.pagination') }}
    </div>
@endsection
