{{--
    Whether the analysis service can classify right now, shown before a teacher
    starts. Recording is never blocked: if the service is down the reading is
    still measured and saved, and can be analyzed again later.
--}}
@if(!empty($mlStatus))
    @if($mlStatus['state'] === 'ready')
        <div class="small text-success mb-3">
            <i class="bi bi-check-circle me-1"></i> Analysis service ready
            @if(!empty($mlStatus['version']))
                <span class="text-muted">&middot; model v{{ $mlStatus['version'] }}</span>
            @endif
        </div>
    @else
        @php $cannotScore = ($mlStatus['stt_ok'] ?? true) === false; @endphp
        <div class="alert alert-{{ $cannotScore ? 'danger' : 'warning' }} d-flex gap-2 mb-3" role="alert">
            <i class="bi bi-{{ $cannotScore ? 'x-octagon-fill' : 'exclamation-triangle-fill' }} mt-1"></i>
            <div>
                <strong>{{ $mlStatus['ok'] ? 'Heads up.' : 'Analysis service not ready.' }}</strong>
                {{ $mlStatus['message'] }}
                @if($cannotScore)
                    <div class="small mt-1">
                        Nothing is made up in the meantime: no result is created, and no accuracy,
                        pace or level is shown for it. Once the service is back, open the learner's
                        history and tap <strong>Analyze</strong> on the saved recording.
                    </div>
                @elseif(! $mlStatus['ok'])
                    <div class="small mt-1">
                        Nothing is guessed in the model's place: the reading is still measured and saved,
                        and you can <strong>Re-analyze</strong> it from its results page once the service is back.
                    </div>
                @endif
            </div>
        </div>
    @endif
@endif
