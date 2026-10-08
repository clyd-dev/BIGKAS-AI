@extends('layouts.app')

@section('title', $learner->getFullName())

@section('content')
    <x-page-header :title="$learner->getFullName()" icon="bi-person"
                   :back="route('learners.index')" back-label="Learners">
        <x-slot:actions>
            @if(auth()->user()->isTeacher())
                <a href="{{ route('assessments.start', $learner) }}" class="btn btn-primary btn-sm">
                    <i class="bi bi-mic me-1"></i> New Assessment
                </a>
            @endif
            <a href="{{ route('learners.form4', $learner) }}" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-file-earmark-person me-1"></i> Form 4
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- Info Cards --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body">
                    @if($learner->reading_level === 'independent')
                        <span class="badge bg-success fs-6 px-3 py-2">Independent</span>
                    @elseif($learner->reading_level === 'instructional')
                        <span class="badge bg-warning text-dark fs-6 px-3 py-2">Instructional</span>
                    @elseif($learner->reading_level === 'frustration')
                        <span class="badge bg-danger fs-6 px-3 py-2">Frustration</span>
                    @else
                        <span class="badge bg-secondary fs-6 px-3 py-2">Not Assessed</span>
                    @endif
                    <div class="small text-muted mt-2">Reading Level</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body">
                    <h4 class="text-primary mb-0">Grade {{ $learner->grade_level }}</h4>
                    <div class="small text-muted mt-1">Grade Level</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body">
                    <h4 class="mb-0">{{ $assessmentCount ?? 0 }}</h4>
                    <div class="small text-muted mt-1">Assessments</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body">
                    <h4 class="mb-0">{{ $learner->schoolClass?->section ?? 'Unassigned' }}</h4>
                    <div class="small text-muted mt-1">Section</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        {{-- Learner Details --}}
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0">Details</h6></div>
                <div class="card-body">
                    <table class="table table-borderless table-sm mb-0">
                        <tr><th class="text-muted" width="40%">LRN</th><td>{{ $learner->lrn ?? 'N/A' }}</td></tr>
                        <tr><th class="text-muted">Gender</th><td>{{ ucfirst($learner->gender ?? 'N/A') }}</td></tr>
                        <tr><th class="text-muted">Birth Date</th><td>{{ $learner->birth_date ? \Carbon\Carbon::parse($learner->birth_date)->format('M d, Y') : 'N/A' }}</td></tr>
                        <tr><th class="text-muted">School</th><td>{{ $learner->school?->name ?? 'N/A' }}</td></tr>
                        <tr><th class="text-muted">Class</th><td>{{ $learner->schoolClass?->section ?? 'N/A' }}</td></tr>
                        <tr><th class="text-muted">Parents</th>
                            <td>
                                @php
                                    $parents = $learner->users->where('role', 'parent');
                                @endphp
                                @if($parents->count() > 0)
                                    <ul class="list-unstyled mb-0">
                                    @foreach($parents as $parent)
                                        <li>{{ $parent->name }}</li>
                                    @endforeach
                                    </ul>
                                @else
                                    <span class="text-muted">None</span>
                                @endif
                            </td>
                        </tr>
                        <tr><th class="text-muted">Added</th><td>{{ $learner->created_at?->format('M d, Y') }}</td></tr>
                    </table>
                </div>
            </div>

            {{-- Student portal PIN (the teacher who added the learner issues it) --}}
            @if(auth()->user()->isTeacher())
                <div class="card border-0 shadow-sm mt-3">
                    <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-key me-1"></i> Student Portal PIN</h6></div>
                    <div class="card-body">
                        @if($learner->pin)
                            <div class="text-center bg-light rounded py-3 mb-3">
                                <div class="small text-muted">PIN for {{ $learner->getFullName() }}</div>
                                <div class="display-6 fw-bold font-monospace user-select-all">{{ $learner->pin }}</div>
                            </div>
                        @elseif($learner->hasLegacyPin())
                            <div class="alert alert-warning small py-2">
                                This PIN was saved before PINs could be viewed, so it cannot be shown. It keeps working, and it
                                becomes viewable the next time the learner logs in. Or issue a new PIN now.
                            </div>
                        @endif
                        <p class="small text-muted mb-2">
                            @if($learner->pin_created_at)
                                PIN issued {{ $learner->pin_created_at->format('M d, Y') }}.
                                @if($learner->isPinExpired()) <span class="text-danger">It has expired; issue a new one.</span> @endif
                            @elseif(! $learner->pin)
                                No PIN has been issued yet.
                            @endif
                        </p>
                        <form method="POST" action="{{ route('learners.generate-pin', $learner) }}"
                              onsubmit="return confirm('{{ $learner->pin_created_at || $learner->pin ? 'Issue a new PIN? The old PIN will stop working.' : 'Issue a PIN for this learner?' }}')">
                            @csrf
                            <button type="submit" class="btn btn-outline-primary w-100">
                                <i class="bi bi-key me-1"></i> {{ $learner->pin_created_at || $learner->pin ? 'Issue New PIN' : 'Generate PIN' }}
                            </button>
                        </form>
                    </div>
                </div>
            @endif

            {{-- Danger Zone (teachers only; admin view is read-only) --}}
            @if(!auth()->user()->isAdmin())
            <div class="card border-danger-subtle shadow-sm mt-3">
                <div class="card-header bg-danger-subtle text-danger-emphasis">
                    <h6 class="mb-0"><i class="bi bi-exclamation-triangle me-1"></i> Danger Zone</h6>
                </div>
                <div class="card-body d-flex gap-2">
                    <a href="{{ route('learners.edit', $learner) }}" class="btn btn-outline-warning flex-fill">
                        <i class="bi bi-pencil me-1"></i> Edit Learner
                    </a>
                    <form method="POST" action="{{ route('learners.destroy', $learner) }}"
                          class="flex-fill" onsubmit="return confirm('Remove this learner? This cannot be undone.')">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger w-100">
                            <i class="bi bi-trash me-1"></i> Delete Learner
                        </button>
                    </form>
                </div>
            </div>
            @endif
        </div>

        {{-- Assessment History --}}
        <div class="col-md-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">Assessment History</h6>
                    <a href="{{ route('reports.learner', $learner) }}" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-file-earmark-bar-graph me-1"></i> Full Report
                    </a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Material</th>
                                    <th>Accuracy</th>
                                    <th>WPM</th>
                                    <th>Level</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($assessments as $assessment)
                                    <tr>
                                        <td>{{ $assessment->created_at?->format('M d, Y') }}</td>
                                        <td>{{ $assessment->material?->title ?? 'N/A' }}</td>
                                        <td>{{ $assessment->results->accuracy_rate ?? '-' }}%</td>
                                        <td>{{ $assessment->results->words_per_minute ?? '-' }}</td>
                                        <td>
                                            @php $level = $assessment->reading_level; @endphp
                                            @if($level === 'independent')
                                                <span class="badge bg-success">Ind.</span>
                                            @elseif($level === 'instructional')
                                                <span class="badge bg-warning text-dark">Inst.</span>
                                            @elseif($level === 'frustration')
                                                <span class="badge bg-danger">Frus.</span>
                                            @else
                                                <span class="badge bg-secondary">-</span>
                                            @endif
                                        </td>
                                        <td>
                                            <a href="{{ route('assessments.results', $assessment) }}" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-3">No assessments yet</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
