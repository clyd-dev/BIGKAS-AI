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
                                    {{-- A reading the model could not classify says so here too. --}}
                                    <x-weakness-status :outcome="$assessment->weaknessOutcome()" chip />
                                    @if($assessment->notMeasuredReason())
                                        <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle d-block mt-1"
                                              title="Not measured from the child's voice, so it is not counted.">Not measured</span>
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

                                    {{-- The only door in the application that destroys a reading.
                                         Teachers only: the admin (principal) browses this screen. --}}
                                    @unless(auth()->user()->isAdmin())
                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                                data-bs-toggle="modal" data-bs-target="#delete-reading-{{ $assessment->id }}"
                                                title="Delete this reading">
                                            <i class="bi bi-trash"></i>
                                            <span class="visually-hidden">Delete this reading</span>
                                        </button>
                                    @endunless
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

    {{-- One confirmation per reading on this page.
         Deliberately a Bootstrap modal with the child's name in escaped body
         text and the id in a data- attribute, not an inline confirm() handler.
         The batch board shipped the other shape once: a name with an apostrophe
         - O'Brien, D'Cruz, common on a Philippine roll - rendered as &#039;,
         the browser decoded the entity before JS parsed it, the handler became
         a syntax error and the destructive form submitted with no dialog at
         all. No handler, no such bug. --}}
    @unless(auth()->user()->isAdmin())
        @foreach($assessments as $assessment)
            @php
                $accuracy = $assessment->effectiveAccuracy();
                $level = $assessment->effectiveReadingLevel();
                // Measured, not merely reviewed: a result feeds the child's
                // reading level whether or not a teacher has looked at it yet.
                $measured = $assessment->result !== null || $assessment->verdict !== null;
            @endphp
            <div class="modal fade" id="delete-reading-{{ $assessment->id }}" tabindex="-1"
                 aria-labelledby="delete-reading-{{ $assessment->id }}-title" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <form method="POST" action="{{ route('assessments.destroy', $assessment) }}" class="modal-content">
                        @csrf
                        @method('DELETE')

                        <div class="modal-header">
                            <h5 class="modal-title" id="delete-reading-{{ $assessment->id }}-title">
                                <i class="bi bi-exclamation-triangle text-danger me-1"></i> Delete this reading?
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>

                        <div class="modal-body">
                            {{-- What is being deleted, named. Never a bare "are you sure". --}}
                            <p class="mb-2">You are about to delete this reading of {{ $learner->getFullName() }}:</p>
                            <ul class="list-unstyled border rounded p-3 bg-body-secondary mb-3 small">
                                <li><strong>Date:</strong> {{ $assessment->created_at?->format('M d, Y') ?? 'undated' }}</li>
                                <li><strong>Material:</strong> {{ $assessment->material?->title ?? 'unknown' }}</li>
                                <li><strong>Reading level:</strong> {{ $level !== null ? ucfirst($level) : 'none recorded' }}</li>
                                <li><strong>Accuracy:</strong>
                                    {{ $accuracy !== null ? number_format((float) $accuracy, 1) . '% accuracy' : 'not measured' }}</li>
                            </ul>

                            {{-- And what goes with it, spelled out: the child's level is
                                 read by the parent portal, the Practice Center and the
                                 admin chart, so this is not only a row disappearing. --}}
                            <p class="mb-1"><strong>This cannot be undone.</strong> Deleted with it:</p>
                            <ul class="small mb-3">
                                <li>the result — accuracy, words per minute, fluency and the analysis behind them</li>
                                <li>the verdict — whatever you or another teacher decided about this reading</li>
                                <li>the comprehension answers the child gave</li>
                                <li>the saved recording of the child reading</li>
                            </ul>
                            {{-- intervention_logs.assessment_result_id is nullOnDelete, so an
                                 intervention already logged is kept but stops pointing at the
                                 reading that prompted it. Nothing is destroyed; the trail is.
                                 Named here because a teacher cannot see it anywhere else. --}}
                            <p class="small mb-3">
                                Any intervention already logged for {{ $learner->first_name }} is <strong>kept</strong>,
                                but will no longer show which reading prompted it.
                            </p>
                            <p class="small mb-0">
                                {{ $learner->getFullName() }}’s <strong>reading level will be recomputed</strong> from the
                                readings that remain, which can change what their parent sees, what the Practice Center
                                recommends and which interventions are suggested for them.
                            </p>

                            @if($measured)
                                {{-- A measured reading feeds the child’s reading level, so it
                                     takes a typed name rather than a click - whether or not a
                                     teacher has reviewed it yet. --}}
                                <hr>
                                <label for="learner-name-{{ $assessment->id }}" class="form-label small mb-1">
                                    This reading has been scored, so it counts towards
                                    {{ $learner->first_name }}’s reading level. To delete it,
                                    type the learner’s name — <strong>{{ $learner->first_name }} {{ $learner->last_name }}</strong>
                                </label>
                                <input type="text" class="form-control" name="learner_name" autocomplete="off"
                                       id="learner-name-{{ $assessment->id }}" placeholder="{{ $learner->first_name }} {{ $learner->last_name }}">
                            @endif
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep this reading</button>
                            <button type="submit" class="btn btn-danger">
                                <i class="bi bi-trash me-1"></i> Delete this reading
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @endforeach
    @endunless

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
