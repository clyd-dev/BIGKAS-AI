@extends('layouts.app')

@section('title', 'Class Report')

@section('content')
    @php $isAdmin = auth()->user()->isAdmin(); @endphp

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">
            <i class="bi bi-file-earmark-text me-2"></i>Grade {{ $report->schoolClass?->grade_level }} – {{ $report->schoolClass?->section }}
            @include('reports.submissions._status', ['status' => $report->status])
        </h4>
        <a href="{{ route('reports.submissions.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Back
        </a>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="row g-3 small">
                <div class="col-md-3"><div class="text-muted">Teacher</div><div class="fw-semibold">{{ $report->teacher?->name ?? '—' }}</div></div>
                <div class="col-md-3"><div class="text-muted">Period</div><div class="fw-semibold">{{ $report->periodLabel() }}</div></div>
                <div class="col-md-3"><div class="text-muted">School year</div><div class="fw-semibold">{{ $report->school_year }}</div></div>
                <div class="col-md-3"><div class="text-muted">Submitted</div><div class="fw-semibold">{{ $report->submitted_at?->format('M d, Y g:i A') }}</div></div>
            </div>
            @if($report->teacher_note)
                <hr>
                <div class="text-muted small">Teacher's note</div>
                <div style="white-space: pre-line;">{{ $report->teacher_note }}</div>
            @endif
        </div>
    </div>

    <div class="mb-2 text-muted small">Enrolment at submission: {{ $report->snapshot['enrolment'] ?? 0 }} learners</div>
    @include('reports.submissions._snapshot', ['snapshot' => $report->snapshot])

    {{-- Principal feedback --}}
    @if($report->principal_comment || $report->reviewed_at)
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header bg-white"><h6 class="mb-0">Principal's feedback</h6></div>
            <div class="card-body">
                <div class="small text-muted mb-1">
                    {{ ucfirst($report->status) }}
                    {{ $report->reviewed_at?->format('M d, Y g:i A') }}
                    @if($report->reviewer) by {{ $report->reviewer->name }} @endif
                </div>
                <div style="white-space: pre-line;">{{ $report->principal_comment ?: 'No comment.' }}</div>
            </div>
        </div>
    @endif

    @if($isAdmin)
        <form method="POST" action="{{ route('reports.submissions.review', $report) }}" class="card border-0 shadow-sm mt-4">
            @csrf
            <div class="card-header bg-white"><h6 class="mb-0">Review this report</h6></div>
            <div class="card-body">
                <label class="form-label">Comment <span class="text-muted small">(required when returning to the teacher)</span></label>
                <textarea name="principal_comment" rows="3" maxlength="2000"
                          class="form-control @error('principal_comment') is-invalid @enderror">{{ old('principal_comment', $report->principal_comment) }}</textarea>
                @error('principal_comment') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="card-footer bg-white d-flex justify-content-end gap-2">
                <button type="submit" name="action" value="returned" class="btn btn-outline-warning px-4">
                    <i class="bi bi-arrow-return-left me-1"></i> Return to Teacher
                </button>
                <button type="submit" name="action" value="reviewed" class="btn btn-success px-4">
                    <i class="bi bi-check2-circle me-1"></i> Mark as Reviewed
                </button>
            </div>
        </form>
    @endif
@endsection
