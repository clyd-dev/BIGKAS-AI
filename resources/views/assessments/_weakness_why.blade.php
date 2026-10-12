{{--
    Skill scores and why the weakness is what it is (see ReadingInterpretationService::weakness).

    Two columns on a wide screen: the four skill rows on the left, the reasoning
    on the right. Everything the narrow version showed is still here - the
    model's pick, its confidence, how well the evidence fits, and what each
    other skill shows - just given room to read.
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

    @once
        @push('styles')
        <style>
            /* Sits inside an uppercase heading, but reads as a sentence. */
            .skill-legend { text-transform: none; }

            .skill-row { padding: .75rem .9rem; border-radius: .5rem; }
            .skill-row + .skill-row { margin-top: .5rem; }
            .skill-row.is-pick {
                background: var(--bs-warning-bg-subtle);
                box-shadow: inset 0 0 0 1px var(--bs-warning-border-subtle);
            }
            .skill-row .progress { height: 6px; }

            .why-panel {
                background: var(--bs-secondary-bg);
                border-radius: .5rem;
                padding: 1rem 1.1rem;
            }
            /* One line per skill, the name set apart from its finding so the
               block scans instead of reading as a wall of prose. */
            .other-skills { list-style: none; padding: 0; margin: 0; }
            .other-skills li { padding: .6rem 0; }
            .other-skills li + li { border-top: 1px solid var(--bs-border-color-translucent); }
            .other-skills .os-name { display: block; font-weight: 600; }
        </style>
        @endpush
    @endonce

    <div class="row g-4">
        {{-- The four skills, scored --}}
        <div class="col-lg-5">
            <h6 class="small text-uppercase text-muted fw-semibold mb-2">
                Skill Scores
                @if($primary['id'])
                    <span class="fw-normal ms-1 skill-legend">
                        <i class="bi bi-star-fill text-warning"></i> = the model's pick
                    </span>
                @endif
            </h6>

            @foreach($skills as $skill)
                @php $s = $status[$skill['status']]; $isPick = $skill['id'] === $primary['id']; @endphp
                <div class="skill-row {{ $isPick ? 'is-pick' : 'bg-body-tertiary' }}">
                    <div class="d-flex justify-content-between align-items-center gap-2 mb-1">
                        <span class="{{ $isPick ? 'fw-bold' : 'fw-semibold' }}">
                            @if($isPick)
                                <i class="bi bi-star-fill text-warning me-1" title="The skill the model picked"></i>
                            @endif{{ $skill['name'] }}
                        </span>
                        <span class="badge bg-{{ $s['tint'] }}-subtle text-{{ $s['tint'] }}-emphasis text-nowrap">
                            {{ $s['label'] }}
                        </span>
                    </div>
                    <div class="small text-muted mb-2">{{ $skill['plain'] }}</div>
                    <div class="progress" role="img" aria-label="{{ $skill['name'] }}: {{ $s['label'] }}">
                        <div class="progress-bar bg-{{ $s['tint'] }}" style="width: {{ $s['fill'] }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Why that pick, and what the rest show --}}
        <div class="col-lg-7">
            <div class="why-panel">
                <div class="d-flex gap-2">
                    <i class="bi {{ $fitIcon[0] }} text-{{ $fitIcon[1] }} fs-5 lh-1 mt-1"></i>
                    <div>
                        <div class="fw-bold mb-1">{{ $primary['headline'] }}</div>
                        <div class="text-body-secondary">{{ $primary['text'] }}</div>
                        @if($primary['confidence_note'])
                            <div class="small text-muted mt-2">
                                <i class="bi bi-info-circle me-1"></i>{{ $primary['confidence_note'] }}
                            </div>
                        @endif
                        @if($weaknessWhy['final_note'])
                            <div class="small mt-2">
                                <i class="bi bi-person-check me-1"></i>{{ $weaknessWhy['final_note'] }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            @if($others->isNotEmpty())
                <h6 class="small text-uppercase text-muted fw-semibold mt-4 mb-1">What the other skills show</h6>
                <ul class="other-skills">
                    @foreach($others as $skill)
                        <li>
                            <span class="os-name">{{ $skill['name'] }}</span>
                            <span class="text-body-secondary">{{ $skill['text'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@endif
