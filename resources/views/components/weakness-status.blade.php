{{--
    One way to say what a learner's weakness is, on every page that shows one.

    Takes Assessment::weaknessOutcome() so a reading the model could not classify
    reads the same on a teacher's report, a history list and a parent's page:
    nothing is guessed, and the wording fits who is reading it.

    audience="teacher" (default) names what happened. audience="parent" uses
    plain words and no machinery. chip shows only a problem worth flagging in a
    crowded list (used on the history table), and nothing when all is well.
--}}
@props(['outcome', 'audience' => 'teacher', 'chip' => false])

@php
    $state = $outcome['state'] ?? 'unclassified';
    $reason = $outcome['reason'] ?? null;
    $name = $outcome['name'] ?? null;
    $id = $outcome['id'] ?? null;
    $isParent = $audience === 'parent';

    $reasonSentence = [
        'unavailable' => 'The analysis model was not running, so no weakness was classified.',
        'model_unsupported' => "The model's suggestion was not supported by the measurements, so no weakness was stored.",
        'invalidated' => 'This reading was marked invalid, so no weakness is counted.',
        'not_analyzed' => 'This reading has not been analyzed yet.',
    ][$reason ?? ''] ?? 'No weakness was classified.';
@endphp

@if($state === 'unclassified')
    @if($chip)
        @if(in_array($reason, ['unavailable', 'model_unsupported'], true))
            <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle" title="{{ $reasonSentence }}">
                {{ $reason === 'unavailable' ? 'Needs re-analysis' : 'Not classified' }}
            </span>
        @endif
    @elseif($isParent)
        <span class="text-muted">Not yet checked</span>
    @else
        <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle" title="{{ $reasonSentence }}">
            Not classified
        </span>
    @endif
@elseif(! $chip)
    @if($id === null || $id === 0)
        {{-- Saved with no label, or no weakness: an honest dash, not a claim. --}}
        <span class="text-muted">{{ $id === 0 ? 'No significant weakness' : '-' }}</span>
    @else
        <span>{{ $isParent ? strtolower($name) : $name }}</span>
        @if($state === 'teacher_set' && ! $isParent)
            <span class="small text-muted" title="Set by the teacher">(teacher)</span>
        @elseif($state === 'legacy' && ! empty($outcome['warning']))
            @if($isParent)
                <span class="small text-muted">(preliminary)</span>
            @else
                <i class="bi bi-exclamation-triangle text-warning" title="{{ $outcome['warning'] }}"></i>
            @endif
        @endif
    @endif
@endif
