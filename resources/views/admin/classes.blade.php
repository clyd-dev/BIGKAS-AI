@extends('layouts.app')

@section('title', 'Classrooms Overview')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-diagram-3 me-2"></i>Teacher & Classrooms Overview</h4>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('admin.classes') }}" class="row g-3 align-items-end">
            <div class="col-md-8">
                <label class="form-label text-muted small">Search Teacher or Section</label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="Search by teacher name or class section..." value="{{ request('search') }}">
                </div>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary w-100">Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="row g-4">
    @forelse($classes as $schoolClass)
        <div class="col-md-6 col-lg-4">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-header bg-white border-bottom-0 pt-3 pb-0">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <span class="badge bg-primary mb-2">Grade {{ $schoolClass->grade_level }}</span>
                            <h5 class="card-title mb-1 fw-bold">{{ $schoolClass->section }}</h5>
                            <p class="text-muted small mb-0"><i class="bi bi-building me-1"></i>{{ $schoolClass->school->name ?? 'No School' }}</p>
                        </div>
                        <div class="text-end">
                            <span class="fs-4 fw-bold text-success">{{ $schoolClass->learners_count }}</span>
                            <div class="small text-muted" style="margin-top: -5px;">Learners</div>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <hr class="text-muted opacity-25">
                    <div class="d-flex align-items-center">
                        <div class="bg-light rounded-circle p-2 me-3">
                            <i class="bi bi-person-video3 text-primary fs-4"></i>
                        </div>
                        <div>
                            <div class="small text-muted text-uppercase fw-semibold" style="letter-spacing: 0.5px;">Adviser / Teacher</div>
                            <div class="fw-bold">{{ $schoolClass->teacher->name ?? 'Unassigned' }}</div>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-light border-top-0 d-grid">
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#collapseClass{{ $schoolClass->id }}">
                        View Learners List <i class="bi bi-chevron-down ms-1"></i>
                    </button>
                    
                    <div class="collapse mt-2" id="collapseClass{{ $schoolClass->id }}">
                        <ul class="list-group list-group-flush rounded border mt-2">
                            @forelse($schoolClass->learners as $learner)
                                <li class="list-group-item d-flex justify-content-between align-items-center py-2 px-3 small">
                                    <span class="text-truncate" style="max-width: 150px;">{{ $learner->getFullName() }}</span>
                                    @if($learner->reading_level === 'independent')
                                        <span class="badge bg-success rounded-pill">Indep</span>
                                    @elseif($learner->reading_level === 'instructional')
                                        <span class="badge bg-warning text-dark rounded-pill">Instr</span>
                                    @elseif($learner->reading_level === 'frustration')
                                        <span class="badge bg-danger rounded-pill">Frust</span>
                                    @else
                                        <span class="badge bg-secondary rounded-pill">N/A</span>
                                    @endif
                                </li>
                            @empty
                                <li class="list-group-item text-muted text-center py-2 small">No learners enrolled</li>
                            @endforelse
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    @empty
        <div class="col-12">
            <div class="text-center text-muted py-5 card border-0 shadow-sm">
                <i class="bi bi-diagram-3 display-4 d-block mb-3"></i>
                <h5>No classrooms found</h5>
                <p>Ensure teachers have classes assigned to them.</p>
            </div>
        </div>
    @endforelse
</div>

<div class="mt-4">
    {{ $classes->links() }}
</div>
@endsection
