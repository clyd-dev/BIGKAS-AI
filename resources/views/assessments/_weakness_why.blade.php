{{--
    Skill scores and why the weakness is what it is (see ReadingInterpretationService::weakness).

    Top: one line per skill — how the reading looks for it.
    Below: why the model's pick was made, and what the other skills show.
--}}
@if(!empty($weaknessWhy))
    @php
        $primary = $weaknessWhy['primary'];
        $skills = $weaknessWhy['skills'];

        $status = [
            'concern' => ['label' => 'Sign of a problem', 'tint' => 'danger',    'fill' => 100],
            'watch'   => ['label' => 'Worth watching',    'tint' => 'warning',   'fill' => 55],
            'ok'      => ['label' => 'No sign',           'tint' => 'success',   'fill' => 12],
            'unknown' => ['label' => 'Can\'t tell',       'tint' => 'secondary', 'fill' => 0],
        ];

        $fitIcon = [
            'supported' => ['bi-check-circle-fill', 'success'],
            'weak' => ['bi-exclamation-circle-fill', 'warning'],
            'unusable' => ['bi-x-octagon-fill', 'danger'],
        ][$primary['fit']] ?? ['bi-info-circle-fill', 'secondary'];

        $others = collect($skills)->reject(fn ($s) => $s['id'] === $primary['id']);
    @endphp

    <h6 class="small text-muted mb-2">
        Skill Scores
        @if($primary['id'])
            <span class="fw-normal ms-1"><i class="bi bi-star-fill text-warning"></i> = the model's pick</span>
        @endif
    </h6>

    @foreach($skills as $skill)
        @php $s = $status[$skill['status']]; $isPick = $skill['id'] === $primary['id']; @endphp
        <div class="mb-3">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                <div class="lh-sm">
                    <span class="{{ $isPick ? 'fw-bold' : '' }}">
                        @if($isPick)<i class="bi bi-star-fill text-warning me-1" title="The skill the model picked"></i>@endif{{ $skill['name'] }}
                    </span>
                    <div class="small text-muted">{{ $skill['plain'] }}</div>
                </div>
                <span class="badge bg-{{ $s['tint'] }}-subtle text-{{ $s['tint'] }}-emphasis text-nowrap">{{ $s['label'] }}</span>
            </div>
            <div class="progress" style="height: 6px;" role="img" aria-label="{{ $skill['name'] }}: {{ $s['label'] }}">
                <div class="progress-bar bg-{{ $s['tint'] }}" style="width: {{ $s['fill'] }}%"></div>
            </div>
        </div>
    @endforeach

    {{-- Why --}}
    <div class="border-top pt-3 mt-3">
        <div class="d-flex gap-2 mb-2">
            <i class="bi {{ $fitIcon[0] }} text-{{ $fitIcon[1] }} mt-1"></i>
            <div>
                <div class="fw-semibold">{{ $primary['headline'] }}</div>
                <div class="text-body-secondary">{{ $primary['text'] }}</div>
                @if($primary['confidence_note'])
                    <div class="small text-muted mt-1">{{ $primary['confidence_note'] }}</div>
                @endif
                @if($weaknessWhy['final_note'])
                    <div class="small mt-1"><i class="bi bi-person-check me-1"></i>{{ $weaknessWhy['final_note'] }}</div>
                @endif
            </div>
        </div>

        @if($others->isNotEmpty())
            <div class="small text-uppercase text-muted fw-semibold mt-3 mb-1">What the other skills show</div>
            <ul class="list-unstyled small mb-0">
                @foreach($others as $skill)
                    <li class="mb-2">
                        <span class="fw-semibold">{{ $skill['plain'] }}.</span>
                        <span class="text-body-secondary">{{ $skill['text'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endif
