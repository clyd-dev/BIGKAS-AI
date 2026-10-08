{{--
    Why the reading landed at its level (see ReadingInterpretationService).

    Reads top to bottom: the answer, where the learner sits on the scale, the
    one thing to be careful about (if any), then the supporting reasons.
--}}
@if(!empty($interpretation))
    @php
        $level = $interpretation['level'];
        $color = ['independent' => 'success', 'instructional' => 'warning', 'frustration' => 'danger'][$level] ?? 'secondary';
        $scale = $interpretation['scale'];

        $callouts = collect($interpretation['reasons'])->where('callout', true);
        $reasons = collect($interpretation['reasons'])->reject(fn ($r) => !empty($r['callout']));

        $icons = [
            'good' => ['bi-check-lg', 'success'],
            'ok' => ['bi-dash-lg', 'warning'],
            'bad' => ['bi-x-lg', 'danger'],
            'warn' => ['bi-exclamation-lg', 'warning'],
            'neutral' => ['bi-arrow-right-short', 'secondary'],
        ];
    @endphp

    @once
        @push('styles')
        <style>
            .why-card .why-number { font-size: 2.75rem; line-height: 1; font-weight: 600; }

            /* Where the learner sits: three equal bands, the one the numbers
               put them in is solid, the others are faint. */
            .level-scale { position: relative; padding-top: 2.1rem; }
            .level-scale__track { display: flex; gap: 3px; }
            .level-scale__band {
                flex: 1; height: .6rem;
                background: var(--bs-secondary-bg);
                border-radius: .6rem;
            }
            .level-scale__band.is-current.band-frustration    { background: var(--bs-danger); }
            .level-scale__band.is-current.band-instructional  { background: var(--bs-warning); }
            .level-scale__band.is-current.band-independent    { background: var(--bs-success); }
            .level-scale__marker {
                position: absolute; top: 0; transform: translateX(-50%);
                text-align: center; line-height: 1;
            }
            .level-scale__bubble {
                display: inline-block; padding: .2rem .55rem; border-radius: .4rem;
                background: var(--bs-body-color); color: var(--bs-body-bg);
                font-size: .8rem; font-weight: 600;
            }
            .level-scale__bubble::after {
                content: ''; display: block; margin: 0 auto;
                border: 5px solid transparent; border-top-color: var(--bs-body-color); border-bottom: 0;
            }
            .level-scale__labels { display: flex; gap: 3px; margin-top: .45rem; }
            .level-scale__labels > div { flex: 1; text-align: center; font-size: .8rem; color: var(--bs-secondary-color); }
            .level-scale__labels .is-current { color: var(--bs-body-color); font-weight: 600; }

            .why-list { list-style: none; padding: 0; margin: 0; }
            .why-list li { display: flex; gap: .85rem; padding: .85rem 0; }
            .why-list li + li { border-top: 1px solid var(--bs-border-color-translucent); }
            .why-icon {
                flex: none; width: 1.75rem; height: 1.75rem; border-radius: 50%;
                display: flex; align-items: center; justify-content: center; font-size: 1rem;
            }
        </style>
        @endpush
    @endonce

    <div class="card border-0 shadow-sm mb-4 why-card">
        <div class="card-body p-4">
            {{-- The answer --}}
            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                <div class="flex-grow-1" style="min-width: 14rem;">
                    <div class="small text-muted mb-1">Why this level?</div>
                    <h4 class="mb-1 text-{{ $color }}-emphasis">{{ $interpretation['label'] }}</h4>
                    <p class="mb-0 text-body-secondary">{{ $interpretation['meaning'] }}</p>
                </div>
                <div class="text-sm-end">
                    <div class="why-number">{{ rtrim(rtrim(number_format($interpretation['accuracy'], 1), '0'), '.') }}%</div>
                    <div class="small text-muted">of words read correctly</div>
                </div>
            </div>

            {{-- Where that falls --}}
            <div class="level-scale mt-3" role="img"
                 aria-label="Accuracy {{ round($interpretation['accuracy']) }}%, which is in the {{ $scale['by_numbers'] }} band">
                <div class="level-scale__marker" style="left: {{ $scale['position'] }}%">
                    <span class="level-scale__bubble">{{ rtrim(rtrim(number_format($interpretation['accuracy'], 1), '0'), '.') }}%</span>
                </div>
                <div class="level-scale__track">
                    @foreach($scale['bands'] as $band)
                        <div class="level-scale__band band-{{ $band['key'] }} {{ $band['key'] === $scale['by_numbers'] ? 'is-current' : '' }}"></div>
                    @endforeach
                </div>
                <div class="level-scale__labels">
                    @foreach($scale['bands'] as $band)
                        <div class="{{ $band['key'] === $scale['by_numbers'] ? 'is-current' : '' }}">
                            {{ $band['label'] }}<br><span>{{ $band['range'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- The one thing to be careful about --}}
            @foreach($callouts as $callout)
                <div class="alert alert-{{ $callout['tone'] === 'bad' ? 'danger' : 'warning' }} d-flex gap-2 mt-4 mb-0" role="alert">
                    <i class="bi bi-exclamation-triangle-fill mt-1"></i>
                    <div>
                        <strong>{{ $callout['title'] }}.</strong> {{ $callout['text'] }}
                    </div>
                </div>
            @endforeach

            {{-- Supporting reasons --}}
            @if($reasons->isNotEmpty())
                <ul class="why-list mt-3">
                    @foreach($reasons as $reason)
                        @php [$icon, $tint] = $icons[$reason['tone']] ?? $icons['neutral']; @endphp
                        <li>
                            <span class="why-icon bg-{{ $tint }}-subtle text-{{ $tint }}-emphasis"><i class="bi {{ $icon }}"></i></span>
                            <div>
                                <div class="fw-semibold">{{ $reason['title'] }}</div>
                                <div class="text-body-secondary">{{ $reason['text'] }}</div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            <p class="small text-muted mt-3 mb-0">
                <i class="bi bi-info-circle me-1"></i>{{ $interpretation['rule'] }}
            </p>
        </div>
    </div>
@endif
