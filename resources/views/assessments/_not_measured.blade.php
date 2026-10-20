{{--
    What the results page shows for a reading that was never measured from the
    child's voice: a placeholder transcript scored by an earlier version when no
    speech engine was running, or a recording with no speech in it.

    There are no numbers here on purpose. The score the placeholder produced was
    flawless (100%, perfect pace, no errors, "independent"), and showing it, even
    struck through, invites someone to accept it.
--}}
@php
    $canAct = auth()->user()->isTeacher();
    $hasRecording = $assessment->audioStatus() === 'available';

    $what = [
        'mock' => "A placeholder transcript was scored instead of the child's voice, because the speech engine was not running when this was analyzed. The accuracy, pace, errors and level it produced are not real, so they are not shown.",
        'no_speech' => 'No speech was detected anywhere in the recording, so there is nothing to score. It may be silent, or the microphone may not have picked the child up.',
    ][$reason] ?? 'This reading was not measured.';
@endphp

<div class="card border-0 shadow-sm mb-4 border-start border-4 border-danger">
    <div class="card-body p-4">
        <div class="d-flex gap-3">
            <i class="bi bi-slash-circle text-danger fs-2 lh-1"></i>
            <div class="flex-grow-1">
                <h4 class="mb-1">Nothing was measured</h4>
                <p class="text-body-secondary mb-3">{{ $what }}</p>

                <ul class="small text-body-secondary mb-3 ps-3">
                    <li>This reading is <strong>not counted</strong> towards {{ $assessment->learner?->first_name ?? 'the learner' }}'s reading level, averages, reports or recommendations.</li>
                    <li>It stays in the history so there is a record that it happened.</li>
                </ul>

                @if($canAct)
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        @if($hasRecording)
                            <form method="POST" action="{{ route('assessments.reanalyze', $assessment) }}">
                                @csrf
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="bi bi-arrow-repeat me-1"></i> Analyze the recording again
                                </button>
                            </form>
                            <span class="small text-muted">Uses the saved recording. The child does not need to read again.</span>
                        @else
                            <a href="{{ route('assessments.start', $assessment->learner) }}" class="btn btn-primary btn-sm">
                                <i class="bi bi-mic me-1"></i> Re-assess
                            </a>
                            <span class="small text-muted">There is no saved recording to analyze again.</span>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- The teacher can still invalidate it, or count the reading themselves. They
     cannot accept it: there is nothing here to accept. --}}
@include('assessments._verdict', ['assessment' => $assessment, 'result' => $result, 'notMeasured' => true])
