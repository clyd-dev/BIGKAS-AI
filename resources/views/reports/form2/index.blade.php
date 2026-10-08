@extends('layouts.app')

@section('title', 'DepEd Form 2')

@push('styles')
<style>
    .form2-table th, .form2-table td { border: 1px solid #dee2e6; padding: 6px 8px; text-align: center; }
    .form2-table th { background: #f1f3f5; }
    .form2-table .grade-row td { font-weight: 600; background: #f8f9fa; }
    .form2-table .total-row td { font-weight: 700; background: #e9ecef; }
</style>
@endpush

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-file-earmark-check me-2"></i>DepEd Form 2 – School Reading Profile</h4>
        <a href="{{ route('reports.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Reports
        </a>
    </div>

    {{-- Selection --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('reports.form2.index') }}" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">School Year</label>
                    <select name="school_year" class="form-select form-select-sm">
                        @foreach($schoolYears as $sy)
                            <option value="{{ $sy }}" {{ $schoolYear === $sy ? 'selected' : '' }}>{{ $sy }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label small">Period</label>
                    <select name="period" class="form-select form-select-sm">
                        @foreach($periods as $key => $label)
                            <option value="{{ $key }}" {{ $period === $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-search me-1"></i> Show</button>
                </div>
            </form>
        </div>
    </div>

    @if($form)
        {{-- Completeness warnings --}}
        @if(!empty($form['missing']))
            <div class="alert alert-warning">
                <strong>{{ count($form['missing']) }} section(s) have no submitted report with screening scores</strong>
                and are not counted yet: {{ implode(', ', $form['missing']) }}.
                <a href="{{ route('reports.submissions.index', ['school_year' => $schoolYear, 'period' => $period]) }}">See Teacher Reports</a>
            </div>
        @endif
        @if(($form['unreviewed'] ?? 0) > 0)
            <div class="alert alert-info">
                {{ $form['unreviewed'] }} section report(s) included here have not been marked as reviewed yet.
            </div>
        @endif

        {{-- Preview + actions --}}
        <div class="d-flex flex-wrap gap-2 mb-3">
            <a href="{{ route('reports.form2.print', ['school_year' => $schoolYear, 'period' => $period]) }}" target="_blank" class="btn btn-outline-primary">
                <i class="bi bi-printer me-1"></i> Print
            </a>
            <a href="{{ route('reports.form2.pdf', ['school_year' => $schoolYear, 'period' => $period]) }}" class="btn btn-outline-primary">
                <i class="bi bi-file-earmark-pdf me-1"></i> Download PDF
            </a>
        </div>

        <div class="small text-muted mb-2">
            {{ $form['school']['name'] }} · {{ $form['school']['district'] }} · {{ $form['school']['division'] }} · {{ $form['school']['region'] }}
        </div>

        <div class="row g-3 mb-4">
            @foreach($form['languages'] as $code => $lang)
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white"><h6 class="mb-0">{{ $lang['label'] }} (Form {{ $code === 'fil' ? '1A' : '1B' }} results)</h6></div>
                        <div class="card-body">
                            @include('reports.form2._table', ['lang' => $lang, 'code' => $code])
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Record submission --}}
        <form method="POST" action="{{ route('reports.form2.submit', ['school_year' => $schoolYear, 'period' => $period]) }}" class="card border-0 shadow-sm mb-4">
            @csrf
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-send-check me-1"></i> Record submission of Form 2</h6></div>
            <div class="card-body">
                <p class="small text-muted">
                    BIGKAS-AI cannot send forms out. Print or download it, sign it, pass it on through your usual channel
                    (Form 2 is used to build Form 5), then record the date here. A copy of the figures is kept with the record.
                </p>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Date submitted</label>
                        <input type="date" name="submitted_on" value="{{ old('submitted_on', now()->toDateString()) }}" max="{{ now()->toDateString() }}"
                               class="form-control @error('submitted_on') is-invalid @enderror" required>
                        @error('submitted_on') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-9">
                        <label class="form-label">Reference <span class="text-muted small">(office, transmittal or tracking no.)</span></label>
                        <input type="text" name="reference" maxlength="120" value="{{ old('reference') }}" class="form-control">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Notes <span class="text-muted small">(optional)</span></label>
                        <textarea name="notes" rows="2" maxlength="2000" class="form-control">{{ old('notes') }}</textarea>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-white text-end">
                <button type="submit" class="btn btn-success px-4"><i class="bi bi-check2-circle me-1"></i> Mark as Submitted / Sent</button>
            </div>
        </form>
    @else
        <div class="alert alert-warning">No sections found yet.</div>
    @endif

    {{-- History --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-clock-history me-1"></i> Submission history</h6></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr><th>Date submitted</th><th>School year</th><th>Period</th><th>Reference</th><th>Recorded by</th><th class="text-end">Action</th></tr>
                    </thead>
                    <tbody>
                        @forelse($submissions as $s)
                            <tr>
                                <td>{{ $s->submitted_on->format('M d, Y') }}</td>
                                <td>{{ $s->school_year }}</td>
                                <td>{{ $s->periodLabel() }}</td>
                                <td>{{ $s->reference ?: '—' }}</td>
                                <td>{{ $s->submitter?->name ?? '—' }}</td>
                                <td class="text-end">
                                    <a href="{{ route('reports.form2.show', $s) }}" target="_blank" class="btn btn-sm btn-primary px-3">
                                        <i class="bi bi-eye me-1"></i> View
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">Nothing recorded as submitted yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
        <div class="small text-muted">
            @if($submissions->total() > 0)
                Showing {{ $submissions->firstItem() }}–{{ $submissions->lastItem() }} of {{ $submissions->total() }}
            @endif
        </div>
        {{ $submissions->onEachSide(1)->links('partials.pagination') }}
    </div>
@endsection
