@extends('layouts.app')

@section('title', 'Assessment Results')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Assessment Results</h4>
        <div>
            <a href="{{ route('reports.learner', $assessment->learner) }}" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-file-pdf me-1"></i> Full Report
            </a>
            <a href="{{ route('assessments.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>

    @php $result = $assessment->result ?? $assessment->results()->first(); @endphp

    @if($result)
        {{-- Summary Cards --}}
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card border-0 shadow-sm text-center {{ $result->accuracy_rate >= 90 ? 'border-start border-success border-4' : 'border-start border-danger border-4' }}">
                    <div class="card-body">
                        <h2 class="mb-0 {{ $result->accuracy_rate >= 90 ? 'text-success' : 'text-danger' }}">{{ number_format($result->accuracy_rate, 1) }}%</h2>
                        <small class="text-muted">Accuracy Rate</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm text-center">
                    <div class="card-body">
                        <h2 class="mb-0 text-primary">{{ number_format($result->words_per_minute, 1) }}</h2>
                        <small class="text-muted">Words Per Minute</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm text-center">
                    <div class="card-body">
                        @if($result->reading_level === 'independent')
                            <h4 class="mb-0"><span class="badge bg-success fs-6 px-3 py-2">Independent</span></h4>
                        @elseif($result->reading_level === 'instructional')
                            <h4 class="mb-0"><span class="badge bg-warning text-dark fs-6 px-3 py-2">Instructional</span></h4>
                        @else
                            <h4 class="mb-0"><span class="badge bg-danger fs-6 px-3 py-2">Frustration</span></h4>
                        @endif
                        <small class="text-muted mt-1 d-block">Reading Level</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm text-center">
                    <div class="card-body">
                        <h2 class="mb-0 text-warning">{{ $result->error_count }}</h2>
                        <small class="text-muted">Total Errors</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            {{-- Error Breakdown --}}
            <div class="col-md-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white"><h6 class="mb-0">Error Breakdown</h6></div>
                    <div class="card-body">
                        <canvas id="errorChart" height="250"></canvas>
                        <div class="mt-3">
                            <table class="table table-sm table-borderless mb-0">
                                <tr><td><span class="badge bg-danger me-1">&nbsp;</span> Substitutions</td><td class="text-end fw-bold">{{ $result->substitution_count }}</td></tr>
                                <tr><td><span class="badge bg-warning me-1">&nbsp;</span> Omissions</td><td class="text-end fw-bold">{{ $result->omission_count }}</td></tr>
                                <tr><td><span class="badge bg-info me-1">&nbsp;</span> Insertions</td><td class="text-end fw-bold">{{ $result->insertion_count }}</td></tr>
                                <tr><td><span class="badge bg-secondary me-1">&nbsp;</span> Self-corrections</td><td class="text-end fw-bold">{{ $result->self_correction_count }}</td></tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Weakness Classification --}}
            <div class="col-md-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white"><h6 class="mb-0">Weakness Classification</h6></div>
                    <div class="card-body">
                        @php
                            $weaknessLabels = config('bigkas.weakness_categories', []);
                        @endphp

                        @if($result->primary_weakness !== null)
                            <div class="alert alert-warning mb-3">
                                <strong>Primary:</strong> {{ $weaknessLabels[$result->primary_weakness]['name'] ?? 'Unknown' }}
                                @if($result->weakness_confidence)
                                    <br><small>Confidence: {{ number_format($result->weakness_confidence * 100, 0) }}%</small>
                                @endif
                            </div>
                        @else
                            <div class="alert alert-success mb-3">
                                <i class="bi bi-check-circle me-1"></i> No significant weakness detected
                            </div>
                        @endif

                        <h6 class="small text-muted mt-3">Skill Scores</h6>
                        @php $mlData = $result->ml_classification_data ?? []; @endphp
                        @foreach($weaknessLabels as $id => $cat)
                            <div class="mb-2">
                                <div class="d-flex justify-content-between small">
                                    <span>{{ $cat['name'] }}</span>
                                    <span>{{ $mlData['all_scores'][$id] ?? 0 }}</span>
                                </div>
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar" style="width: {{ min(100, ($mlData['all_scores'][$id] ?? 0) * 2) }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Assessment Info --}}
            <div class="col-md-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white"><h6 class="mb-0">Details</h6></div>
                    <div class="card-body">
                        <table class="table table-borderless table-sm mb-0">
                            <tr><th class="text-muted">Learner</th><td>{{ $assessment->learner?->full_name ?? 'N/A' }}</td></tr>
                            <tr><th class="text-muted">Material</th><td>{{ $assessment->material?->title ?? 'N/A' }}</td></tr>
                            <tr><th class="text-muted">Language</th><td>{{ ucfirst($assessment->language) }}</td></tr>
                            <tr><th class="text-muted">Duration</th><td>{{ gmdate('i:s', $result->duration_seconds ?? 0) }}</td></tr>
                            <tr><th class="text-muted">Date</th><td>{{ $assessment->created_at?->format('M d, Y g:i A') }}</td></tr>
                        </table>
                    </div>
                </div>

                {{-- Recommendations --}}
                @if(!empty($recommendations))
                    <div class="card border-0 shadow-sm mt-3">
                        <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-lightbulb me-1"></i> Recommended Interventions</h6></div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush">
                                  @foreach($recommendations as $rec)
                                      <div class="list-group-item">
                                          <div class="d-flex justify-content-between align-items-start">
                                              <div>
                                                  <strong>{{ $rec->name ?? $rec['name'] ?? 'Intervention' }}</strong>
                                                  <p class="small text-muted mb-1">{{ $rec->description ?? $rec['description'] ?? '' }}</p>
                                                  <span class="badge bg-light text-dark border">{{ ucfirst($rec->activity_type ?? 'Activity') }}</span>
                                                  <span class="badge bg-light text-dark border">{{ $rec->estimated_duration ?? 15 }} mins</span>
                                              </div>
                                              
                                              @if(isset($rec->id))
                                              <form action="{{ route('interventions.assign') }}" method="POST">
                                                  @csrf
                                                  <input type="hidden" name="intervention_id" value="{{ $rec->id }}">
                                                  <input type="hidden" name="learner_id" value="{{ $assessment->learner_id }}">
                                                  <input type="hidden" name="assessment_result_id" value="{{ $result->id }}">
                                                  <button type="submit" class="btn btn-sm btn-outline-primary" title="Assign to Learner">
                                                      <i class="bi bi-plus-circle"></i> Assign
                                                  </button>
                                              </form>
                                              @endif
                                          </div>
                                      </div>
                                  @endforeach
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- Word Comparison --}}
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header bg-white"><h6 class="mb-0">Word-by-Word Comparison</h6></div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-1">
                    @foreach($result->word_comparison_data ?? [] as $word)
                        @if(($word['status'] ?? '') === 'correct')
                            <span class="badge bg-success-subtle text-success border border-success px-2 py-1">{{ $word['reference'] }}</span>
                        @elseif(($word['status'] ?? '') === 'substitution')
                            <span class="badge bg-danger-subtle text-danger border border-danger px-2 py-1" title="Said: {{ $word['spoken'] ?? '?' }}">
                                <s>{{ $word['reference'] }}</s> {{ $word['spoken'] ?? '?' }}
                            </span>
                        @elseif(($word['status'] ?? '') === 'omission')
                            <span class="badge bg-warning-subtle text-warning border border-warning px-2 py-1" title="Omitted">
                                <s>{{ $word['reference'] }}</s>
                            </span>
                        @elseif(($word['status'] ?? '') === 'insertion')
                            <span class="badge bg-info-subtle text-info border border-info px-2 py-1" title="Inserted">
                                +{{ $word['spoken'] ?? '?' }}
                            </span>
                        @endif
                    @endforeach
                </div>
                <div class="mt-3 small">
                    <span class="badge bg-success-subtle text-success me-2">Correct</span>
                    <span class="badge bg-danger-subtle text-danger me-2">Substitution</span>
                    <span class="badge bg-warning-subtle text-warning me-2">Omission</span>
                    <span class="badge bg-info-subtle text-info">Insertion</span>
                </div>
            </div>
        </div>
    @else
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-1"></i> This assessment has not been analyzed yet.
        </div>
    @endif

@push('scripts')
<script>
    @if($result)
    new Chart(document.getElementById('errorChart'), {
        type: 'doughnut',
        data: {
            labels: ['Substitutions', 'Omissions', 'Insertions', 'Self-corrections'],
            datasets: [{
                data: [{{ $result->substitution_count }}, {{ $result->omission_count }}, {{ $result->insertion_count }}, {{ $result->self_correction_count }}],
                backgroundColor: ['#dc3545', '#ffc107', '#0dcaf0', '#6c757d']
            }]
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });
    @endif
</script>
@endpush
@endsection
