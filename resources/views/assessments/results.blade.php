@extends('layouts.app')

@section('title', 'Assessment Results')

@push('styles')
<style>
    /* Reading comparison -------------------------------------------- */
    .reading-pane {
        font-size: 1.05rem;
        line-height: 2.2;
        max-height: 420px;
        overflow-y: auto;
    }
    .reading-pane .w {
        padding: .1rem .25rem;
        border-radius: .3rem;
    }

    /* One colour per miscue. Not scoped to the pane, so the key swatches and
       the error table use exactly the same colours as the words. */
    .w-mis  { background-color: #ffcf9e; color: #7a3200; font-weight: 500; }                                            /* mispronounced */
    .w-sub  { background-color: var(--bs-danger-bg-subtle); color: var(--bs-danger-text-emphasis); font-weight: 500; }  /* different word */
    .w-omit { background-color: #ffe98a; color: #5c4600; font-weight: 500; }                                            /* skipped: yellow, word still readable */
    .w-nr   { background-color: transparent; color: var(--bs-secondary-color); box-shadow: inset 0 0 0 1px var(--bs-border-color); } /* not reached: an empty outline, never attempted */
    .w-ins  { background-color: var(--bs-info-bg-subtle); color: var(--bs-info-text-emphasis); font-weight: 500; }      /* added */
    .w-rep  { background-color: #e2d9f3; color: #432874; font-weight: 500; }                                            /* repeated */
    .w-sc   { background-color: var(--bs-success-bg-subtle); color: var(--bs-success-text-emphasis); text-decoration: line-through; } /* self-corrected */
    .w-off  { background-color: var(--bs-secondary-bg); color: var(--bs-secondary-color); font-style: italic; }         /* not reading */

    /* A word the speech engine wasn't sure it heard: a wavy line, like a
       spell-checker's "not sure about this one". It sits under any colour. */
    .w-unclear {
        text-decoration: underline wavy;
        text-decoration-color: color-mix(in srgb, var(--bs-body-color) 65%, transparent);
        text-decoration-thickness: 1.5px;
        text-underline-offset: .3em;
        text-decoration-skip-ink: none;
        cursor: help;
    }

    /* A silence before a word */
    .w-pause {
        display: inline-block;
        font-size: .7rem;
        letter-spacing: -.05em;
        color: var(--bs-secondary-color);
        border: 1px solid var(--bs-border-color);
        border-radius: 1rem;
        padding: .05rem .45rem;
        margin: 0 .15rem;
        white-space: nowrap;
        cursor: help;
    }
    .w-pause-long { color: #fff; background-color: var(--bs-secondary); border-color: var(--bs-secondary); }

    /* Where the reading ended */
    .w-stop {
        display: inline-block;
        font-size: .75rem;
        font-weight: 600;
        color: var(--bs-secondary-color);
        border: 1px dashed var(--bs-border-color);
        border-radius: 1rem;
        padding: .05rem .6rem;
        margin: 0 .25rem;
    }

    /* The key above the comparison */
    .comparison-key { column-gap: 1.1rem; row-gap: .35rem; }
    .key-chip { display: inline-flex; align-items: center; gap: .4rem; font-size: .8rem; color: var(--bs-secondary-color); cursor: help; }
    .key-chip b { color: var(--bs-body-color); }
    .key-swatch { width: .9rem; height: .9rem; border-radius: .25rem; display: inline-block; border: 1px solid rgba(0, 0, 0, .12); }
    .key-sample { font-size: .8rem; padding: 0 .1rem; }

    /* Colour swatches in the error table */
    .legend-swatch {
        width: .85rem;
        height: .85rem;
        border-radius: .2rem;
        display: inline-block;
        border: 1px solid rgba(0, 0, 0, .15);
    }
</style>
@endpush

@section('content')
    @php $result = $assessment->result; @endphp

    <x-page-header title="Assessment Results" icon="bi-bar-chart"
                   :back="route('assessments.index')" back-label="Assessments">
        <x-slot:meta>
            <div class="pg-sub">
                {{ $assessment->learner?->full_name ?? 'N/A' }}
                &middot; {{ $assessment->material?->title ?? 'N/A' }}
                &middot; {{ $assessment->created_at?->format('M d, Y g:i A') }}
            </div>
        </x-slot:meta>
        <x-slot:actions>
            <a href="{{ route('reports.learner', $assessment->learner) }}" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-file-earmark-bar-graph me-1"></i> Full Report
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- A reading that was never measured from the child's voice shows nothing but
         what happened. Its numbers (a flawless score of a placeholder, a silent
         recording) are not the child's, so they are not shown at all. --}}
    @php $notMeasured = $result ? $assessment->notMeasuredReason() : null; @endphp

    @if($result && $notMeasured)
        @include('assessments._not_measured', ['assessment' => $assessment, 'result' => $result, 'reason' => $notMeasured])
    @elseif($result)
        @php
            $comparison = $result->word_comparison_data ?? [];
            $comprehensionAnswers = $assessment->comprehensionAnswers;
            $hasComprehension = $comprehensionAnswers->isNotEmpty();
        @endphp

        {{-- ── Summary tiles ──────────────────────────────────────────── --}}
        @php
            // Tiles show what stands as the learner's record, which is the
            // teacher's verdict when one exists.
            $shownAccuracy = $assessment->effectiveAccuracy();
            $shownWpm = $assessment->effectiveWordsPerMinute();
            $edited = $assessment->wasEdited();

            // Word reading and comprehension are different scales answering
            // different questions, so each level says which one produced it.
            // "Reading Level" appears only when both halves were measured.
            $levels = $assessment->readingLevels();
            $levelTiles = array_values(array_filter([
                $levels['word'], $levels['comprehension'], $levels['overall'],
            ]));

            $levelBadge = fn ($level) => match ($level) {
                'independent' => ['bg-success', 'Independent'],
                'instructional' => ['bg-warning text-dark', 'Instructional'],
                'frustration' => ['bg-danger', 'Frustration'],
                default => ['bg-secondary', 'Not assessed'],
            };
        @endphp

        {{-- What was measured --}}
        <div class="row g-3 mb-3">
            <div class="{{ $hasComprehension ? 'col-6 col-lg-3' : 'col-6 col-lg-4' }}">
                <div class="card border-0 shadow-sm h-100 text-center">
                    <div class="card-body">
                        <h2 class="mb-0 {{ $shownAccuracy >= 97 ? 'text-success' : ($shownAccuracy >= 90 ? 'text-warning' : 'text-danger') }}">
                            {{ number_format((float) $shownAccuracy, 1) }}%
                        </h2>
                        <small class="text-muted">Word Accuracy</small>
                        @if($levels['word'] && $levels['word']['total'])
                            <div class="small text-muted mt-1">
                                {{ $levels['word']['correct'] }} of {{ $levels['word']['total'] }} words
                            </div>
                        @endif
                        @if($edited && $assessment->verdict->manual_scoring)
                            <span class="badge bg-primary-subtle text-primary-emphasis d-block mt-1">Re-scored</span>
                        @endif
                    </div>
                </div>
            </div>
            <div class="{{ $hasComprehension ? 'col-6 col-lg-3' : 'col-6 col-lg-4' }}">
                <div class="card border-0 shadow-sm h-100 text-center">
                    <div class="card-body">
                        <h2 class="mb-0 text-primary">{{ number_format((float) $shownWpm, 0) }}</h2>
                        <small class="text-muted">Words / Minute</small>
                    </div>
                </div>
            </div>
            @if($hasComprehension)
                @php $comp = $levels['comprehension']; @endphp
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100 text-center">
                        <div class="card-body">
                            <h2 class="mb-0 {{ $comp['score'] >= 80 ? 'text-success' : ($comp['score'] >= 59 ? 'text-warning' : 'text-danger') }}">
                                {{ $comp['correct'] }}/{{ $comp['total'] }}
                            </h2>
                            <small class="text-muted">Comprehension</small>
                            <div class="small text-muted mt-1">{{ rtrim(rtrim(number_format($comp['score'], 1), '0'), '.') }}% correct</div>
                        </div>
                    </div>
                </div>
            @endif
            <div class="{{ $hasComprehension ? 'col-6 col-lg-3' : 'col-6 col-lg-4' }}">
                <div class="card border-0 shadow-sm h-100 text-center">
                    <div class="card-body">
                        <h2 class="mb-0 text-secondary">{{ $result->error_count }}</h2>
                        <small class="text-muted">Total Errors</small>
                    </div>
                </div>
            </div>
        </div>

        {{-- The level each measure puts the learner at --}}
        <div class="row g-3 mb-4">
            @foreach($levelTiles as $tile)
                @php [$badgeClass, $badgeText] = $levelBadge($tile['level']); @endphp
                <div class="col-12 col-md">
                    <div class="card border-0 shadow-sm h-100 text-center {{ isset($tile['basis']) ? 'border-top border-3 border-primary-subtle' : '' }}">
                        <div class="card-body">
                            <span class="badge {{ $badgeClass }} fs-6 px-3 py-2">{{ $badgeText }}</span>
                            <div class="small text-muted mt-2 fw-semibold">{{ $tile['label'] }}</div>
                            @if(isset($tile['score']))
                                <div class="small text-muted">
                                    from {{ rtrim(rtrim(number_format($tile['score'], 1), '0'), '.') }}%
                                </div>
                            @elseif(isset($tile['basis']))
                                <div class="small text-muted" title="{{ $tile['basis'] }}">
                                    word reading &amp; comprehension together
                                </div>
                            @endif
                            @if(!empty($tile['from_teacher']) || ($edited && count($levelTiles) === 1))
                                <span class="badge bg-primary-subtle text-primary-emphasis mt-1">Teacher-set</span>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- ── Teacher decision: the authoritative answer ─────────────── --}}
        @include('assessments._verdict', ['assessment' => $assessment, 'result' => $result])

        {{-- ── Why this level ─────────────────────────────────────────── --}}
        @include('assessments._interpretation', ['interpretation' => $interpretation])

        {{-- ── Reading comparison (first thing the teacher reads) ─────── --}}
        @include('assessments._reading_comparison', ['comparison' => $comparison])

        {{-- ── Recording & transcription ──────────────────────────────── --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-mic me-1"></i> Raw Audio Transcription</h6></div>
            <div class="card-body">
                <p class="p-3 bg-light rounded fst-italic text-muted">
                    "{{ $assessment->getTranscribedText() ?: 'No transcription available.' }}"
                </p>

                {{-- The recording itself, so the teacher can check the transcription by ear --}}
                @if($assessment->audioStatus() === 'available')
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="small text-muted text-nowrap"><i class="bi bi-play-circle me-1"></i> Recording</span>
                        <audio controls preload="metadata" class="flex-grow-1" style="min-width: 240px;"
                               id="assessmentRecording" src="{{ $assessment->getAudioUrl() }}"></audio>
                        <a href="{{ $assessment->getAudioUrl() }}" download class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-download me-1"></i> Download
                        </a>
                    </div>
                @elseif($assessment->audioStatus() === 'missing')
                    <div class="alert alert-warning small mb-0">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        The recording was saved, but the file can no longer be found on the server. It may have been deleted.
                    </div>
                @else
                    <div class="alert alert-secondary small mb-0">
                        <i class="bi bi-mic-mute me-1"></i>
                        <strong>No recording was saved for this assessment.</strong>
                        It was analysed from a live recording that wasn't kept, so it can't be played back or recovered.
                        New assessments save their recording automatically.
                    </div>
                @endif

                {{-- Noise, background sounds and anything said that wasn't the passage --}}
                @include('assessments._audio_capture', [
                    'audio' => $advisor['audio'],
                    'canPlay' => $assessment->audioStatus() === 'available',
                ])
            </div>
        </div>

        {{-- ── Comprehension test ────────────────────────────────────── --}}
        @if($hasComprehension)
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-patch-question me-1"></i> Comprehension Test</h6>
                    @php $comp = $levels['comprehension']; @endphp
                    <span class="badge fs-6 {{ $comp['score'] >= 80 ? 'bg-success' : ($comp['score'] >= 59 ? 'bg-warning text-dark' : 'bg-danger') }}">
                        {{ $comp['correct'] }}/{{ $comp['total'] }} &middot; {{ rtrim(rtrim(number_format($comp['score'], 1), '0'), '.') }}%
                    </span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:40px">#</th>
                                    <th>Question</th>
                                    <th>Learner's Answer</th>
                                    <th>Correct Answer</th>
                                    <th class="text-center" style="width:60px">Result</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($comprehensionAnswers as $i => $answer)
                                    <tr>
                                        <td>{{ $i + 1 }}</td>
                                        <td>{{ $answer->question_text }}</td>
                                        <td class="{{ $answer->is_correct ? 'text-success' : 'text-danger' }}">
                                            {{ $answer->selected_text ?? 'No answer' }}
                                        </td>
                                        <td class="text-muted">{{ $answer->correct_text }}</td>
                                        <td class="text-center">
                                            @if($answer->is_correct)
                                                <i class="bi bi-check-circle-fill text-success"></i>
                                            @else
                                                <i class="bi bi-x-circle-fill text-danger"></i>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif

        {{-- ── Weakness Classification ───────────────────────────────── --}}
        {{-- Full width: the skill rows and their explanations were unreadable
             squeezed into a third of the page. --}}
        @php
            $weaknessLabels = config('bigkas.weakness_categories', []);

            // One answer to "what is this learner's weakness?", read the same way
            // on every page (Assessment::weaknessOutcome).
            $weakness = $assessment->weaknessOutcome();
            $unclassified = $weakness['state'] === 'unclassified';

            $reasonText = [
                'unavailable' => 'The analysis model was not running when this reading was analyzed, so no weakness was classified. Nothing was guessed in its place.',
                'model_unsupported' => 'The model made a suggestion, but it is not supported by the measurements in this reading, so no weakness was stored.',
                'invalidated' => 'This result was marked invalid, so no weakness is counted for it.',
                'not_analyzed' => 'This reading has not been analyzed yet.',
            ][$weakness['reason'] ?? ''] ?? 'No weakness was classified for this reading.';

            // Re-analyzing needs the saved recording; without one only a new
            // reading will do. Teachers only: the admin view is read-only.
            $canAct = auth()->user()->isTeacher();
            $canReanalyze = $canAct
                && in_array($weakness['reason'], ['unavailable', 'model_unsupported'], true)
                && $assessment->audioStatus() === 'available';
            $needsReassess = $canAct
                && in_array($weakness['reason'], ['unavailable', 'model_unsupported'], true)
                && ! $canReanalyze;

            // Legacy results keep their label and carry a warning about its source.
            $sourceWarning = $weakness['state'] === 'legacy' && $weakness['warning']
                ? [$weakness['source'] === 'rule' ? 'Not from the model' : 'Source not recorded', $weakness['warning']]
                : null;
        @endphp
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h6 class="mb-0"><i class="bi bi-clipboard-pulse me-1"></i> Weakness Classification</h6>
                @if($unclassified)
                    <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle fs-6">
                        <i class="bi bi-dash-circle me-1"></i> Not classified
                    </span>
                @elseif($weakness['id'] !== null && $weakness['id'] !== 0)
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle fs-6">
                            Primary: {{ $weakness['name'] ?? 'Unknown' }}
                        </span>
                        @if($weakness['state'] === 'teacher_set')
                            <span class="small text-muted"><i class="bi bi-person-check me-1"></i>Set by you</span>
                        @elseif($result->weakness_confidence)
                            <span class="small text-muted">{{ number_format($result->weakness_confidence * 100, 0) }}% confidence</span>
                        @endif
                        @if($sourceWarning)
                            <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle"
                                  title="{{ $sourceWarning[1] }}">
                                <i class="bi bi-exclamation-triangle me-1"></i>{{ $sourceWarning[0] }}
                            </span>
                        @endif
                    </div>
                @else
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle fs-6">
                            <i class="bi bi-check-circle me-1"></i> No significant weakness detected
                        </span>
                        @if($weakness['state'] === 'teacher_set')
                            <span class="small text-muted"><i class="bi bi-person-check me-1"></i>Set by you</span>
                        @endif
                        @if($sourceWarning)
                            <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle"
                                  title="{{ $sourceWarning[1] }}">
                                <i class="bi bi-exclamation-triangle me-1"></i>{{ $sourceWarning[0] }}
                            </span>
                        @endif
                    </div>
                @endif
            </div>
            <div class="card-body">
                @if($unclassified)
                    {{-- Honest, not silent: what happened and what can be done. --}}
                    <div class="alert alert-secondary mb-3" role="alert">
                        <div class="d-flex gap-2">
                            <i class="bi bi-info-circle-fill mt-1"></i>
                            <div class="flex-grow-1">
                                <strong>Weakness classification unavailable.</strong>
                                {{ $reasonText }}
                                The accuracy, pace, levels and miscues on this page were measured from the reading and are unaffected.
                                @if($canAct && $weakness['reason'] !== 'invalidated')
                                    <div class="small mt-1">You can also set the weakness yourself with <strong>Override</strong> above.</div>
                                @endif
                            </div>
                        </div>
                        @if($canReanalyze)
                            <form method="POST" action="{{ route('assessments.reanalyze', $assessment) }}" class="mt-3">
                                @csrf
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="bi bi-arrow-repeat me-1"></i> Re-analyze
                                </button>
                                <span class="small text-muted ms-2">Uses the saved recording. The child does not need to read again.</span>
                            </form>
                        @elseif($needsReassess)
                            <div class="mt-3">
                                <a href="{{ route('assessments.start', $assessment->learner) }}" class="btn btn-primary btn-sm">
                                    <i class="bi bi-mic me-1"></i> Re-assess
                                </a>
                                <span class="small text-muted ms-2">There is no saved recording to analyze again.</span>
                            </div>
                        @endif
                    </div>
                @elseif($sourceWarning)
                    <div class="alert alert-warning d-flex gap-2 py-2 mb-3" role="alert">
                        <i class="bi bi-exclamation-triangle-fill mt-1"></i>
                        <div>
                            <strong>{{ $sourceWarning[0] }}.</strong> {{ $sourceWarning[1] }}
                            The skill rows below read the measured numbers directly and are unaffected.
                        </div>
                    </div>
                @endif
                @include('assessments._weakness_why', ['weaknessWhy' => $weaknessWhy])
            </div>
        </div>

        {{-- ── Diagnosis ─────────────────────────────────────────────── --}}
        <div class="row g-3">
            {{-- Error Breakdown --}}
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white"><h6 class="mb-0">Error Breakdown</h6></div>
                    <div class="card-body">
                        <canvas id="errorChart" height="220"></canvas>
                        @php
                            // Finer breakdown when the result has it; results analysed
                            // earlier only know substitutions/omissions/insertions.
                            $mc = $result->ml_analysis_json['miscues'] ?? null;
                            $breakdown = $mc
                                ? [
                                    ['Mispronunciations', 'w-mis', $mc['mispronunciation'], true],
                                    ['Substitutions', 'w-sub', $mc['substitution'], true],
                                    ['Omissions', 'w-omit', $mc['omission'], true],
                                    ['Not reached', 'w-nr', $mc['not_reached'] ?? 0, true],
                                    ['Insertions', 'w-ins', $mc['insertion'], true],
                                    ['Repetitions', 'w-rep', $mc['repetition'], true],
                                    ['Self-corrections', 'w-sc', $mc['self_correction'], false],
                                    ['Hesitations (1s+)', 'bg-secondary-subtle', $mc['hesitation'], false],
                                    ['Long pauses (3s+)', 'bg-secondary', $mc['long_pause'], false],
                                ]
                                : [
                                    ['Substitutions', 'w-sub', $result->substitution_count, true],
                                    ['Omissions', 'w-omit', $result->omission_count, true],
                                    ['Insertions', 'w-ins', $result->insertion_count, true],
                                    ['Self-corrections', 'w-sc', $result->self_correction_count, false],
                                ];
                        @endphp
                        <table class="table table-sm table-borderless mb-0 mt-3">
                            @foreach($breakdown as [$label, $class, $count, $scored])
                                <tr class="{{ $scored ? '' : 'text-muted' }}">
                                    <td>
                                        <span class="legend-swatch {{ $class }} me-1"></span>
                                        {{ $label }}
                                        @unless($scored) <span class="small">(not scored)</span> @endunless
                                    </td>
                                    <td class="text-end fw-bold">{{ $count }}</td>
                                </tr>
                            @endforeach
                        </table>
                        @php $errorPatterns = $result->ml_analysis_json['error_patterns'] ?? []; @endphp
                        @if(!empty($errorPatterns))
                            <div class="mt-3 pt-3 border-top">
                                <h6 class="small text-muted mb-2">Specific Linguistic Patterns</h6>
                                <div class="d-flex flex-wrap gap-2">
                                    @foreach($errorPatterns as $pattern => $count)
                                        <span class="badge bg-light text-dark border">
                                            {{ ucwords(str_replace('_', ' ', $pattern)) }}: <span class="fw-bold text-danger">{{ $count }}</span>
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Details + Recommendations --}}
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white"><h6 class="mb-0">Details</h6></div>
                    <div class="card-body">
                        <table class="table table-borderless table-sm mb-0">
                            <tr><th class="text-muted">Learner</th><td>{{ $assessment->learner?->full_name ?? 'N/A' }}</td></tr>
                            <tr><th class="text-muted">Material</th><td>{{ $assessment->material?->title ?? 'N/A' }}</td></tr>
                            <tr><th class="text-muted">Language</th><td>{{ $assessment->language === 'fil' ? 'Filipino' : 'English' }}</td></tr>
                            <tr><th class="text-muted">Type</th><td>{{ ucwords(str_replace('_', ' ', $assessment->assessment_type ?? 'oral reading')) }}</td></tr>
                            <tr><th class="text-muted">Duration</th><td>{{ gmdate('i:s', (int) ($result->duration_seconds ?? 0)) }}</td></tr>
                            <tr><th class="text-muted">Date</th><td>{{ $assessment->created_at?->format('M d, Y g:i A') }}</td></tr>
                        </table>
                    </div>
                </div>

                {{-- Interventions follow the weakness. With none classified there is
                     nothing to recommend, and the page says so rather than going quiet. --}}
                @if($unclassified && $weakness['reason'] !== 'invalidated')
                    <div class="card border-0 shadow-sm mt-3">
                        <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-lightbulb me-1"></i> Interventions</h6></div>
                        <div class="card-body small text-muted">
                            None suggested yet, because no weakness was classified for this reading.
                            They appear once it is re-analyzed, or once you set the weakness yourself.
                        </div>
                    </div>
                @elseif(collect($recommendations)->isNotEmpty())
                    <div class="card border-0 shadow-sm mt-3">
                        <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-lightbulb me-1"></i> Recommended Interventions</h6></div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush">
                                @foreach($recommendations as $rec)
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-start gap-2">
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
                                                    <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap" title="Assign to Learner">
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

        {{-- ── How the AI produced this (below Weakness Classification) ── --}}
        @include('assessments._ai_analysis', ['advisor' => $advisor])

        {{-- ── How to read it: the process, the numbers, a short FAQ ──── --}}
        @include('assessments._how_to_read', ['assessment' => $assessment, 'result' => $result, 'advisor' => $advisor])
    @else
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-1"></i> This assessment has not been analyzed yet.
        </div>
    @endif

@push('scripts')
<script>
    @if($result && ! $notMeasured)
    new Chart(document.getElementById('errorChart'), {
        type: 'doughnut',
        data: {
            {{-- Scored miscues only: self-corrections and pauses aren't errors. --}}
            @php $mcChart = $result->ml_analysis_json['miscues'] ?? null; @endphp
            @if($mcChart)
            labels: ['Mispronunciations', 'Substitutions', 'Omissions', 'Not reached', 'Insertions', 'Repetitions'],
            datasets: [{
                data: [{{ $mcChart['mispronunciation'] }}, {{ $mcChart['substitution'] }}, {{ $mcChart['omission'] }}, {{ $mcChart['not_reached'] ?? 0 }}, {{ $mcChart['insertion'] }}, {{ $mcChart['repetition'] }}],
                backgroundColor: ['#fd7e14', '#dc3545', '#ffc107', '#adb5bd', '#0dcaf0', '#6f42c1']
            }]
            @else
            labels: ['Substitutions', 'Omissions', 'Insertions'],
            datasets: [{
                data: [{{ $result->substitution_count }}, {{ $result->omission_count }}, {{ $result->insertion_count }}],
                backgroundColor: ['#dc3545', '#ffc107', '#0dcaf0']
            }]
            @endif
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });
    @endif
</script>
@endpush
@endsection
