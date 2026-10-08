@extends('layouts.app')

@section('title', 'Classrooms Overview')

@section('content')
<x-page-header title="Classrooms Overview" icon="bi-diagram-3"
               subtitle="Old Sagay Elementary School – Grades 3 to 6">
    <x-slot:actions>
        <a href="{{ route('admin.schools') }}" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-pencil me-1"></i> Manage Grades &amp; Sections
        </a>
    </x-slot:actions>
</x-page-header>

@php
    $grouped = $classes->groupBy('grade_level')->sortKeys();
    $gradeColors = [3 => 'primary', 4 => 'success', 5 => 'warning', 6 => 'danger'];
@endphp

@if($classes->isEmpty())
    <div class="card border-0 shadow-sm">
        <div class="text-center text-muted py-5">
            <i class="bi bi-diagram-3 display-4 d-block mb-3"></i>
            <h5>No classrooms set up yet</h5>
            <p class="mb-3">Add grades and sections first.</p>
            <a href="{{ route('admin.schools') }}" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-circle me-1"></i>Add Grade &amp; Section
            </a>
        </div>
    </div>
@else
    <div class="row g-4">
        @foreach($grouped as $grade => $sections)
            @php $color = $gradeColors[$grade] ?? 'secondary'; @endphp
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    {{-- Grade header --}}
                    <div class="card-header bg-{{ $color }} text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-mortarboard me-2"></i>Grade {{ $grade }}</h5>
                        <span class="badge bg-white text-{{ $color }} rounded-pill">
                            {{ $sections->count() }} section{{ $sections->count() > 1 ? 's' : '' }}
                            &middot; {{ $sections->sum('learners_count') }} learners
                        </span>
                    </div>

                    {{-- Sections list --}}
                    <div class="card-body p-0">
                        @foreach($sections as $class)
                            <div class="border-bottom px-3 py-3">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-semibold">
                                            <i class="bi bi-people me-1 text-{{ $color }}"></i>
                                            Section {{ $class->section }}
                                            <span class="badge bg-{{ $color }} bg-opacity-10 text-{{ $color }} ms-1">
                                                {{ $class->learners_count }} learner{{ $class->learners_count != 1 ? 's' : '' }}
                                            </span>
                                        </div>
                                        <div class="small text-muted mt-1">
                                            <i class="bi bi-person-video3 me-1"></i>
                                            <strong>Adviser:</strong>
                                            {{ $class->teacher->name ?? 'Unassigned' }}
                                        </div>
                                        <div class="small text-muted">
                                            <i class="bi bi-calendar3 me-1"></i>S.Y. {{ $class->school_year ?? '—' }}
                                        </div>
                                    </div>

                                    {{-- Reading level mini-bar --}}
                                    <div class="text-end" style="min-width: 120px;">
                                        @php
                                            $total = $class->learners_count ?: 1;
                                            $indep  = $class->learners->where('reading_level','independent')->count();
                                            $instr  = $class->learners->where('reading_level','instructional')->count();
                                            $frust  = $class->learners->where('reading_level','frustration')->count();
                                        @endphp
                                        <div class="progress" style="height: 8px;" title="Independent / Instructional / Frustration">
                                            <div class="progress-bar bg-success" style="width:{{ round($indep/$total*100) }}%"></div>
                                            <div class="progress-bar bg-warning" style="width:{{ round($instr/$total*100) }}%"></div>
                                            <div class="progress-bar bg-danger"  style="width:{{ round($frust/$total*100) }}%"></div>
                                        </div>
                                        <div class="small text-muted mt-1" style="font-size:0.72rem;">
                                            <span class="text-success">{{ $indep }}</span> /
                                            <span class="text-warning">{{ $instr }}</span> /
                                            <span class="text-danger">{{ $frust }}</span>
                                        </div>
                                    </div>
                                </div>

                                {{-- Learner list toggle --}}
                                <div class="mt-2">
                                    <button class="btn btn-sm btn-outline-secondary py-0 px-2"
                                            data-bs-toggle="collapse"
                                            data-bs-target="#learners{{ $class->id }}">
                                        <i class="bi bi-list-ul me-1"></i>View Learner List
                                        <i class="bi bi-chevron-down ms-1"></i>
                                    </button>
                                </div>
                                <div class="collapse mt-2" id="learners{{ $class->id }}">
                                    @if($class->learners->isEmpty())
                                        <p class="text-muted small fst-italic mb-0">No learners enrolled in this section.</p>
                                    @else
                                        <ul class="list-group list-group-flush rounded border">
                                            @foreach($class->learners->sortBy(fn($l) => $l->last_name) as $learner)
                                                <li class="list-group-item d-flex justify-content-between align-items-center py-2 px-3 small">
                                                    <span class="text-truncate" style="max-width: 160px;">{{ $learner->getFullName() }}</span>
                                                    @php $lvl = $learner->reading_level; @endphp
                                                    @if($lvl === 'independent')
                                                        <span class="badge bg-success rounded-pill">Independent</span>
                                                    @elseif($lvl === 'instructional')
                                                        <span class="badge bg-warning text-dark rounded-pill">Instructional</span>
                                                    @elseif($lvl === 'frustration')
                                                        <span class="badge bg-danger rounded-pill">Frustration</span>
                                                    @else
                                                        <span class="badge bg-secondary rounded-pill">Not Assessed</span>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endif
@endsection
