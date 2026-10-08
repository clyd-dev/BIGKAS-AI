{{--
    What the microphone picked up besides the child reading.

    $audio   — AnalysisAdvisorService::audioSummary()
    $canPlay — whether the recording is available to play

    Written for teachers, not audio engineers: a plain sentence about how clear
    the recording was, a few plain facts, and each odd moment with a button to
    listen to it. The decibel figures are there, but folded away.
--}}
@php
    $tint = ['good' => 'success', 'warn' => 'warning', 'bad' => 'danger', 'neutral' => 'secondary'][$audio['tone']] ?? 'secondary';
    $headIcon = ['good' => 'bi-check-circle-fill', 'warn' => 'bi-exclamation-triangle-fill', 'bad' => 'bi-x-octagon-fill', 'neutral' => 'bi-info-circle-fill'][$audio['tone']] ?? 'bi-info-circle-fill';

    $itemIcon = ['talk' => 'bi-chat-dots', 'noise' => 'bi-volume-up', 'sound' => 'bi-soundwave', 'doubtful' => 'bi-question-circle'];
    $clock = fn ($seconds) => gmdate('i:s', (int) $seconds);
@endphp

@once
    @push('styles')
    <style>
        .mic-fact { border: 1px solid var(--bs-border-color); border-radius: .5rem; padding: .6rem .8rem; height: 100%; }
        .mic-fact .label { font-size: .8rem; color: var(--bs-secondary-color); }
        .mic-fact .value { font-weight: 600; }
        .mic-bar { height: .45rem; border-radius: 1rem; background: var(--bs-secondary-bg); overflow: hidden; margin-top: .4rem; }
        .mic-bar > div { height: 100%; background: var(--bs-primary); border-radius: 1rem; }
        .mic-item { display: flex; gap: .75rem; padding: .75rem 0; align-items: flex-start; }
        .mic-item + .mic-item { border-top: 1px solid var(--bs-border-color-translucent); }
        .mic-item .icon { flex: none; width: 1.9rem; height: 1.9rem; border-radius: 50%; background: var(--bs-secondary-bg); color: var(--bs-secondary-color); display: flex; align-items: center; justify-content: center; }
    </style>
    @endpush
@endonce

<div class="border-top mt-4 pt-4">
    <h6 class="mb-3"><i class="bi bi-soundwave me-1"></i> What the microphone picked up</h6>

    {{-- How clear was it? --}}
    <div class="alert alert-{{ $tint }} d-flex gap-2 mb-3" role="status">
        <i class="bi {{ $headIcon }} mt-1"></i>
        <div><strong>{{ $audio['headline'] }}.</strong> {{ $audio['detail'] }}</div>
    </div>

    @if($audio['measured'])
        <div class="row g-2 mb-3">
            <div class="col-sm-6">
                <div class="mic-fact">
                    <div class="label">Time the child was speaking</div>
                    <div class="value">{{ $audio['speech_seconds'] }} of {{ $audio['total_seconds'] }} seconds</div>
                    <div class="mic-bar" role="img" aria-label="{{ $audio['speech_pct'] }}% of the recording is speech"><div style="width: {{ $audio['speech_pct'] }}%"></div></div>
                </div>
            </div>
            @if($audio['noise'])
                <div class="col-sm-3 col-6">
                    <div class="mic-fact">
                        <div class="label">Background noise</div>
                        <div class="value text-{{ ['Low' => 'success', 'Medium' => 'warning', 'High' => 'danger'][$audio['noise']] }}-emphasis">{{ $audio['noise'] }}</div>
                    </div>
                </div>
            @endif
            <div class="col-sm-3 col-6">
                <div class="mic-fact">
                    <div class="label">Voice volume</div>
                    <div class="value {{ $audio['distorted'] ? 'text-danger-emphasis' : '' }}">{{ $audio['distorted'] ? 'Too loud' : 'Fine' }}</div>
                    @if($audio['distorted'])
                        <div class="small text-muted">The sound is distorted. Move the microphone back a little.</div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Anything that wasn't the child reading --}}
    <div class="fw-semibold mb-1">Other sounds heard</div>

    @if(empty($audio['items']))
        <p class="text-body-secondary mb-0">
            <i class="bi bi-check-circle text-success me-1"></i>
            Nothing besides the child reading was picked up.
        </p>
    @else
        <div>
            @foreach($audio['items'] as $item)
                <div class="mic-item">
                    <span class="icon"><i class="bi {{ $itemIcon[$item['kind']] ?? 'bi-dot' }}"></i></span>
                    <div class="flex-grow-1">
                        <div class="fw-semibold">{{ $item['title'] }}</div>
                        @if($item['quote'])
                            <div class="fst-italic">&ldquo;{{ $item['quote'] }}&rdquo;</div>
                        @endif
                        <div class="small text-body-secondary">{{ $item['detail'] }}</div>
                    </div>
                    @if($canPlay && $item['at'] !== null)
                        <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap seek-btn"
                                data-seek="{{ $item['at'] }}" title="Play the recording from here">
                            <i class="bi bi-play-fill"></i> {{ $clock($item['at']) }}
                        </button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    {{-- For the curious --}}
    @if(!empty($audio['technical']))
        <details class="mt-3">
            <summary class="small text-muted" style="cursor: pointer;">Technical details</summary>
            <dl class="row small text-body-secondary mt-2 mb-0">
                @foreach($audio['technical'] as $label => $value)
                    <dt class="col-sm-5 fw-normal">{{ $label }}</dt>
                    <dd class="col-sm-7 mb-1">{{ $value }}</dd>
                @endforeach
                <dd class="col-12 mt-1 mb-0">
                    A voice 20 dB or more above the background is clean; under 10 dB, words are often misheard.
                </dd>
            </dl>
        </details>
    @endif
</div>

@once
    @push('scripts')
    <script>
        // "▶ 0:04" buttons play the recording from that moment.
        document.addEventListener('click', function (e) {
            const button = e.target.closest('.seek-btn');
            const player = document.getElementById('assessmentRecording');
            if (!button || !player) return;

            player.currentTime = parseFloat(button.dataset.seek) || 0;
            player.play();
            player.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    </script>
    @endpush
@endonce
