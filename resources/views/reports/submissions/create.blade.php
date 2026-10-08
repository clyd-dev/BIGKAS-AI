@extends('layouts.app')

@section('title', 'Submit Report')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-send me-2"></i>Submit Report to Principal</h4>
        <a href="{{ route('reports.submissions.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Back
        </a>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="mb-3">
                <div class="fw-semibold">Grade {{ $class->grade_level }} – {{ $class->section }}</div>
                <div class="small text-muted">School year {{ $class->school_year }} · {{ $snapshot['enrolment'] }} learners</div>
            </div>
            <p class="text-muted small mb-0">
                The reading profile below is captured at the moment you submit. It cannot be edited afterwards;
                if the principal returns it, you can submit an updated one.
            </p>
        </div>
    </div>

    @include('reports.submissions._snapshot', ['snapshot' => $snapshot])

    <form method="POST" action="{{ route('reports.submissions.store') }}" class="card border-0 shadow-sm mt-4">
        @csrf
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label">Reporting period</label>
                    <select name="period" class="form-select @error('period') is-invalid @enderror" required
                            onchange="window.location='{{ route('reports.submissions.create') }}?period=' + this.value">
                        @foreach($periods as $key => $label)
                            <option value="{{ $key }}" {{ old('period', $period) === $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('period') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-12">
                    <label class="form-label">Note to the principal <span class="text-muted small">(optional)</span></label>
                    <textarea name="teacher_note" rows="3" maxlength="2000"
                              class="form-control @error('teacher_note') is-invalid @enderror"
                              placeholder="Context, concerns or interventions you want the principal to know about...">{{ old('teacher_note') }}</textarea>
                    @error('teacher_note') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>
        </div>
        <div class="card-footer bg-white text-end">
            <button type="submit" class="btn btn-primary px-4"><i class="bi bi-send me-1"></i> Submit Report</button>
        </div>
    </form>
@endsection
