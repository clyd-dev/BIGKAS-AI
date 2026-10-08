@extends('layouts.app')

@section('title', $learner->getFullName() . ' — Assessment History')

@section('content')
    <x-page-header :title="$learner->getFullName()" icon="bi-clock-history" subtitle="Assessment history"
                   :back="route('assessments.index')" back-label="Assessments" />

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Assessor</th>
                            <th>Material</th>
                            <th>Accuracy</th>
                            <th>WPM</th>
                            <th>Level</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($assessments as $assessment)
                            <tr>
                                <td>
                                    {{ $assessment->created_at?->format('M d, Y') }}
                                    {{-- Invalidated results stay on record; they just don't count --}}
                                    @if($assessment->isInvalidated())
                                        <span class="badge bg-danger-subtle text-danger-emphasis d-block mt-1"
                                              title="{{ $assessment->verdict->reason }}">Invalid — not counted</span>
                                    @elseif($assessment->wasEdited())
                                        <span class="badge bg-primary-subtle text-primary-emphasis d-block mt-1">Teacher-edited</span>
                                    @elseif($assessment->result && !$assessment->isReviewed())
                                        <span class="badge bg-warning-subtle text-warning-emphasis d-block mt-1">Pending review</span>
                                    @elseif($assessment->awaitingAnalysis())
                                        {{-- The reading happened but produced no score, so it needs
                                             analysing again, not re-recording. --}}
                                        <span class="badge bg-warning-subtle text-warning-emphasis d-block mt-1">Not scored — recording saved</span>
                                    @endif
                                </td>
                                <td>{{ $assessment->assessor?->name ?? '—' }}</td>
                                <td>{{ Str::limit($assessment->material?->title ?? 'N/A', 30) }}</td>
                                <td class="{{ $assessment->isInvalidated() ? 'text-muted text-decoration-line-through' : '' }}">
                                    {{ $assessment->effectiveAccuracy() !== null ? number_format((float) $assessment->effectiveAccuracy(), 1) : '-' }}%
                                </td>
                                <td class="{{ $assessment->isInvalidated() ? 'text-muted text-decoration-line-through' : '' }}">
                                    {{ $assessment->effectiveWordsPerMinute() !== null ? number_format((float) $assessment->effectiveWordsPerMinute(), 0) : '-' }}
                                </td>
                                <td>
                                    @php $level = $assessment->effectiveReadingLevel(); @endphp
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
                                <td class="text-end">
                                    @if($assessment->status === 'completed')
                                        <a href="{{ route('assessments.results', $assessment) }}" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye me-1"></i> View Result
                                        </a>
                                        <a href="{{ route('assessments.form3', $assessment) }}" target="_blank" class="btn btn-sm btn-outline-secondary" title="Phil-IRI Form {{ $assessment->language === 'fil' ? '3A' : '3B' }}">
                                            <i class="bi bi-file-earmark-text me-1"></i> Form {{ $assessment->language === 'fil' ? '3A' : '3B' }}
                                        </a>
                                    @elseif(auth()->user()->isAdmin())
                                        <span class="badge bg-secondary">In progress</span>
                                    @elseif($assessment->awaitingAnalysis())
                                        <a href="{{ route('assessments.show', $assessment) }}" class="btn btn-sm btn-primary">
                                            <i class="bi bi-cpu me-1"></i> Analyze again
                                        </a>
                                    @elseif($assessment->status === 'audio_uploaded')
                                        <form method="POST" action="{{ route('assessments.analyze', $assessment) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-primary">
                                                <i class="bi bi-cpu me-1"></i> Analyze
                                            </button>
                                        </form>
                                    @else
                                        <a href="{{ route('assessments.show', $assessment) }}" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-mic me-1"></i> Continue
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">No assessments found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Pagination --}}
    <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
        <div class="small text-muted">
            @if($assessments->total() > 0)
                Showing {{ $assessments->firstItem() }}–{{ $assessments->lastItem() }} of {{ $assessments->total() }}
            @endif
        </div>
        {{ $assessments->onEachSide(1)->links('partials.pagination') }}
    </div>
@endsection
