@extends('layouts.app')

@section('title', $learner->full_name . ' - Assessment Detail')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-file-earmark-bar-graph me-2"></i>Assessment Detail</h4>
        <div>
            <a href="{{ route('parent.children.assessments', $learner) }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back to Results
            </a>
        </div>
    </div>

    {{-- Assessment Info --}}
    <div class="row g-3 mb-4">
        <div class="col-md-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0">Assessment Information</h6></div>
                <div class="card-body">
                    <table class="table table-borderless table-sm mb-0">
                        <tr>
                            <th class="text-muted" style="width: 150px;">Learner</th>
                            <td>{{ $learner->full_name }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">Material</th>
                            <td>{{ $assessment->material?->title ?? 'N/A' }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">Language</th>
                            <td>{{ ucfirst($assessment->material?->language ?? '-') }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">Grade Level</th>
                            <td>Grade {{ $assessment->material?->grade_level ?? '-' }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">Assessed By</th>
                            <td>{{ $assessment->assessor?->name ?? '-' }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">Date</th>
                            <td>{{ $assessment->created_at?->format('F d, Y h:i A') }}</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            @if($result)
                <div class="card border-0 shadow-sm text-center">
                    <div class="card-body">
                        @if($result->reading_level === 'independent')
                            <span class="badge bg-success fs-5 px-4 py-2">Independent</span>
                        @elseif($result->reading_level === 'instructional')
                            <span class="badge bg-warning text-dark fs-5 px-4 py-2">Instructional</span>
                        @elseif($result->reading_level === 'frustration')
                            <span class="badge bg-danger fs-5 px-4 py-2">Frustration</span>
                        @else
                            <span class="badge bg-secondary fs-5 px-4 py-2">N/A</span>
                        @endif
                        <div class="small text-muted mt-2">Reading Level</div>
                    </div>
                </div>
            @endif
        </div>
    </div>

    @if($result)
        {{-- Results --}}
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card border-0 shadow-sm text-center">
                    <div class="card-body">
                        <h3 class="mb-0 {{ ($result->accuracy_rate ?? 0) >= 80 ? 'text-success' : (($result->accuracy_rate ?? 0) >= 60 ? 'text-warning' : 'text-danger') }}">
                            {{ number_format($result->accuracy_rate ?? 0, 1) }}%
                        </h3>
                        <small class="text-muted">Accuracy Rate</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm text-center">
                    <div class="card-body">
                        <h3 class="mb-0 text-info">{{ $result->words_per_minute ?? 0 }}</h3>
                        <small class="text-muted">Words Per Minute</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm text-center">
                    <div class="card-body">
                        <h3 class="mb-0 text-primary">{{ $result->total_words ?? 0 }}</h3>
                        <small class="text-muted">Total Words</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm text-center">
                    <div class="card-body">
                        <h3 class="mb-0 text-danger">{{ $result->total_errors ?? 0 }}</h3>
                        <small class="text-muted">Total Errors</small>
                    </div>
                </div>
            </div>
        </div>

        {{-- Comparison with Previous --}}
        @if($comparison)
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-arrow-left-right me-1"></i>Comparison with Previous Assessment</h6></div>
                <div class="card-body">
                    <div class="row g-3 text-center">
                        <div class="col-md-4">
                            <div class="small text-muted">Accuracy Change</div>
                            @php $accChange = $comparison['accuracy_change'] ?? 0; @endphp
                            <h5 class="{{ $accChange >= 0 ? 'text-success' : 'text-danger' }}">
                                <i class="bi bi-{{ $accChange >= 0 ? 'arrow-up' : 'arrow-down' }}"></i>
                                {{ $accChange >= 0 ? '+' : '' }}{{ number_format($accChange, 1) }}%
                            </h5>
                        </div>
                        <div class="col-md-4">
                            <div class="small text-muted">WPM Change</div>
                            @php $wpmChange = $comparison['wpm_change'] ?? 0; @endphp
                            <h5 class="{{ $wpmChange >= 0 ? 'text-success' : 'text-danger' }}">
                                <i class="bi bi-{{ $wpmChange >= 0 ? 'arrow-up' : 'arrow-down' }}"></i>
                                {{ $wpmChange >= 0 ? '+' : '' }}{{ round($wpmChange) }}
                            </h5>
                        </div>
                        <div class="col-md-4">
                            <div class="small text-muted">Error Change</div>
                            @php $errChange = $comparison['error_change'] ?? 0; @endphp
                            <h5 class="{{ $errChange <= 0 ? 'text-success' : 'text-danger' }}">
                                <i class="bi bi-{{ $errChange <= 0 ? 'arrow-down' : 'arrow-up' }}"></i>
                                {{ $errChange >= 0 ? '+' : '' }}{{ $errChange }}
                            </h5>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Error Breakdown --}}
        @if($errorBreakdown && count($errorBreakdown) > 0)
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Error Breakdown</h6></div>
                <div class="card-body">
                    <div class="row g-3">
                        @foreach($errorBreakdown as $type => $count)
                            <div class="col-md-3">
                                <div class="text-center">
                                    <h4 class="mb-0 text-danger">{{ $count }}</h4>
                                    <small class="text-muted">{{ ucfirst(str_replace('_', ' ', $type)) }}</small>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        {{-- Reading Level Explanation --}}
        @if($readingLevelInfo)
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-info-circle me-1"></i>What This Reading Level Means</h6></div>
                <div class="card-body">
                    <p class="mb-2"><strong>{{ $readingLevelInfo['name'] ?? ucfirst($result->reading_level ?? 'N/A') }}</strong></p>
                    <p class="mb-0 text-muted">{{ $readingLevelInfo['description'] ?? 'No description available.' }}</p>
                </div>
            </div>
        @endif

        {{-- Recommendations for Parents --}}
        @if($result->primary_weakness !== null)
            @php $weaknessLabels = config('bigkas.weakness_categories', []); @endphp
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-house-heart me-1"></i>How You Can Help at Home</h6></div>
                <div class="card-body">
                    <div class="alert alert-info mb-0">
                        <strong>Primary Area for Improvement:</strong> {{ $weaknessLabels[$result->primary_weakness]['name'] ?? ucfirst(str_replace('_', ' ', $result->primary_weakness)) }}
                        <hr>
                        <p class="mb-0">Check the <a href="{{ route('parent.children.interventions', $learner) }}">Home Activities</a> tab for specific exercises assigned by the teacher to help improve this area.</p>
                    </div>
                </div>
            </div>
        @endif
    @else
        <div class="alert alert-warning">
            <i class="bi bi-info-circle me-1"></i> This assessment has not been analyzed yet. Results will appear once the teacher completes the analysis.
        </div>
    @endif
@endsection
