{{--
    AI/ML analysis — the decision-support panel.

    Shows what actually produced this result (which speech engine, which
    classifier), how confident each stage was, how reliable the model is in
    general, and anything about the recording that argues for a re-assessment
    rather than trusting the numbers.
--}}
@php
    $findings = $advisor['findings'] ?? [];
    $metrics = $advisor['metrics'] ?? [];
    $pipeline = $advisor['pipeline'] ?? [];
    $model = $advisor['model'] ?? [];

    $stateClass = [
        'ok' => 'bg-success-subtle text-success-emphasis',
        'warning' => 'bg-warning-subtle text-warning-emphasis',
        'critical' => 'bg-danger-subtle text-danger-emphasis',
        'unknown' => 'bg-light text-muted',
    ];

    $findingStyle = [
        'critical' => ['class' => 'border-danger bg-danger-subtle', 'icon' => 'bi-exclamation-octagon-fill text-danger'],
        'warning' => ['class' => 'border-warning bg-warning-subtle', 'icon' => 'bi-exclamation-triangle-fill text-warning'],
        'info' => ['class' => 'border-info bg-info-subtle', 'icon' => 'bi-info-circle-fill text-info'],
        'ok' => ['class' => 'border-success bg-success-subtle', 'icon' => 'bi-check-circle-fill text-success'],
    ];
@endphp

<div class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0"><i class="bi bi-cpu me-1"></i> AI/ML Analysis</h6>
        <span class="small text-muted">How this result was produced &mdash; and how much to trust it</span>
    </div>

    <div class="card-body">
        <div class="row g-4">
            {{-- Pipeline + metrics --}}
            <div class="col-lg-6">
                <h6 class="small text-uppercase text-muted mb-2">Pipeline</h6>
                <div class="d-flex flex-column gap-2 mb-4">
                    @foreach($pipeline as $stage => $info)
                        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                            <span class="small text-muted">{{ $stage }}</span>
                            <span class="badge {{ $stateClass[$info['state']] ?? $stateClass['unknown'] }}">
                                {{ $info['label'] }}
                            </span>
                        </div>
                    @endforeach
                </div>

                <h6 class="small text-uppercase text-muted mb-2">Measurements</h6>
                <div class="row g-2">
                    @foreach($metrics as $label => $value)
                        <div class="col-6">
                            <div class="border rounded p-2 h-100">
                                <div class="small text-muted">{{ $label }}</div>
                                <div class="fw-semibold">{{ $value }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if(!empty($model['version']) || !empty($model['test_accuracy']))
                    <div class="mt-3 p-2 bg-light rounded small text-muted">
                        <i class="bi bi-diagram-3 me-1"></i>
                        {{ $model['type'] ?? 'Model' }}@if(!empty($model['version'])) v{{ $model['version'] }}@endif
                        @if(!empty($model['test_accuracy']))
                            &middot; validation accuracy {{ $model['test_accuracy'] }}%
                        @endif
                        @if(!empty($model['cv_accuracy']))
                            &middot; cross-validation {{ $model['cv_accuracy'] }}%
                        @endif
                        @if(!empty($model['samples']))
                            &middot; trained on {{ $model['samples'] }} samples
                        @endif
                        <div class="mt-1">
                            Predictions are advisory. Your professional judgement decides the learner's record.
                        </div>
                    </div>
                @endif
            </div>

            {{-- Findings / suggested next step --}}
            <div class="col-lg-6">
                <h6 class="small text-uppercase text-muted mb-2">What to check before you decide</h6>
                <div class="d-flex flex-column gap-2">
                    @foreach($findings as $finding)
                        @php $style = $findingStyle[$finding['level']] ?? $findingStyle['info']; @endphp
                        <div class="border rounded p-3 {{ $style['class'] }}">
                            <div class="d-flex gap-2">
                                <i class="bi {{ $style['icon'] }} mt-1"></i>
                                <div>
                                    <div class="fw-semibold">{{ $finding['title'] }}</div>
                                    <div class="small">{{ $finding['message'] }}</div>
                                    @if(!empty($finding['action']))
                                        <div class="small mt-1">
                                            <i class="bi bi-arrow-right-short"></i>
                                            <strong>{{ $finding['action'] }}</strong>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>
