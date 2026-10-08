@extends('layouts.app')

@section('title', 'Teacher Reports')

@section('content')
    <x-page-header title="Teacher Reports" icon="bi-inbox"
                   :back="route('reports.index')" back-label="Reports" />

    {{-- Filters --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('reports.submissions.index') }}" class="row g-2 align-items-end">
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
                    <label class="form-label small">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="not_submitted" {{ $status === 'not_submitted' ? 'selected' : '' }}>Not submitted</option>
                        <option value="submitted" {{ $status === 'submitted' ? 'selected' : '' }}>Submitted</option>
                        <option value="reviewed" {{ $status === 'reviewed' ? 'selected' : '' }}>Reviewed</option>
                        <option value="returned" {{ $status === 'returned' ? 'selected' : '' }}>Returned</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-search me-1"></i> Filter</button>
                    <a href="{{ route('reports.submissions.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
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
                            <th>Status</th>
                            <th>Submitted</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($classes as $class)
                            @php $report = $class->reports->first(); @endphp
                            <tr>
                                <td class="fw-semibold">Grade {{ $class->grade_level }} – {{ $class->section }}</td>
                                <td>{{ $class->teacher?->name ?? '—' }}</td>
                                <td>@include('reports.submissions._status', ['status' => $report?->status])</td>
                                <td>{{ $report?->submitted_at?->format('M d, Y') ?? '—' }}</td>
                                <td class="text-end">
                                    @if($report)
                                        <a href="{{ route('reports.submissions.show', $report) }}" class="btn btn-sm btn-primary px-3">
                                            <i class="bi bi-eye me-1"></i> View
                                        </a>
                                    @else
                                        <span class="small text-muted">Waiting for teacher</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">No sections found.</td></tr>
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
