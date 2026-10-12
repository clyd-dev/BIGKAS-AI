{{--
    "How to read this result": the process behind the numbers, what each number
    means (with this learner's own figures filled in), and a short FAQ.

    Everything here is explanation only. The measured values come from the
    same variables the rest of the page uses, and the cut-offs are read from
    config so the text cannot drift from the rules that actually run.
--}}
@php
    $hrAccuracy = (float) $assessment->effectiveAccuracy();
    $hrWpm = (float) $assessment->effectiveWordsPerMinute();
    $hrLevels = $assessment->readingLevels();
    $hrComp = $hrLevels['comprehension'] ?? null;

    $hrGrade = (int) ($assessment->learner?->grade_level ?? 0);
    $hrBench = config('bigkas.wpm_benchmarks.' . $hrGrade);

    $hrConfidence = $result->weakness_confidence ? (int) round($result->weakness_confidence * 100) : null;
    $hrModel = $advisor['model'] ?? [];
    $hrTestAcc = $hrModel['test_accuracy'] ?? null;

    $hrN = fn ($v, $d = 1) => rtrim(rtrim(number_format($v, $d), '0'), '.');
@endphp

<div class="card border-0 shadow-sm mt-4" id="how-to-read">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0"><i aria-hidden="true" class="bi bi-journal-text me-1"></i> How to read this result</h6>
        <span class="small text-muted">The process, the numbers and common questions</span>
    </div>

    <div class="card-body">
        {{-- 1. The process --}}
        <h6 class="small text-uppercase text-muted fw-semibold mb-3">How the result was produced</h6>
        <ol class="list-group list-group-numbered mb-4">
            <li class="list-group-item border-0 bg-body-tertiary mb-2 rounded">
                <strong>Listen.</strong> The recording is checked for speech versus background noise, then a speech
                model (Whisper) writes down the words and when each one was said.
            </li>
            <li class="list-group-item border-0 bg-body-tertiary mb-2 rounded">
                <strong>Compare.</strong> The words that were heard are lined up against the passage.
                Every passage word becomes correct, mispronounced, substituted, skipped or not reached.
            </li>
            <li class="list-group-item border-0 bg-body-tertiary mb-2 rounded">
                <strong>Count.</strong> Accuracy, words per minute, pauses, repetitions and (when questions were
                asked) the comprehension score are worked out by simple arithmetic. No AI is involved in this step.
            </li>
            <li class="list-group-item border-0 bg-body-tertiary mb-2 rounded">
                <strong>Suggest.</strong> A Random Forest model looks at 12 of those rates together and suggests the
                one skill that looks weakest. It is a suggestion, not a measurement.
            </li>
            <li class="list-group-item border-0 bg-body-tertiary mb-2 rounded">
                <strong>Check.</strong> The suggestion is kept only if the measured evidence supports it. If the
                recording was too unclear, or the numbers don't back the pick, the page says so instead.
            </li>
            <li class="list-group-item border-0 bg-body-tertiary rounded">
                <strong>You decide.</strong> Accept, override or invalidate the result above. The AI's original
                values are kept beside your decision.
            </li>
        </ol>

        {{-- 2. The numbers --}}
        <h6 class="small text-uppercase text-muted fw-semibold mb-2">What each number means</h6>
        <div class="table-responsive mb-4">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Number</th>
                        <th scope="col">This learner</th>
                        <th scope="col">How it is obtained, and how to read it</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <th scope="row">Word accuracy</th>
                        <td class="fw-semibold text-nowrap">{{ $hrN($hrAccuracy) }}%</td>
                        <td class="small text-body-secondary">
                            Correct words &divide; words in the passage. Phil-IRI word-reading levels:
                            <strong>97% and above</strong> independent, <strong>90&ndash;96%</strong> instructional,
                            <strong>below 90%</strong> frustration. Counted by rules, not predicted by the AI.
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Words per minute</th>
                        <td class="fw-semibold text-nowrap">{{ number_format($hrWpm, 0) }}</td>
                        <td class="small text-body-secondary">
                            Correctly read words &divide; recording length in minutes, so mistakes and long silences lower it.
                            @if($hrBench)
                                The grade {{ $hrGrade }} benchmark in this system is a minimum of
                                <strong>{{ $hrBench['min'] }}</strong>, a target of <strong>{{ $hrBench['target'] }}</strong>.
                            @endif
                            Speed alone is not a diagnosis: a slow but accurate reader and a fast but careless
                            one need different help.
                        </td>
                    </tr>
                    @if($hrComp)
                        <tr>
                            <th scope="row">Comprehension</th>
                            <td class="fw-semibold text-nowrap">{{ $hrComp['correct'] }}/{{ $hrComp['total'] }} ({{ $hrN($hrComp['score']) }}%)</td>
                            <td class="small text-body-secondary">
                                Questions answered correctly. <strong>80% and above</strong> independent,
                                <strong>59&ndash;79%</strong> instructional, <strong>below 59%</strong> frustration.
                                Comprehension is only judged from the questions, never from how the voice sounds.
                            </td>
                        </tr>
                    @endif
                    <tr>
                        <th scope="row">Total errors</th>
                        <td class="fw-semibold text-nowrap">{{ $result->error_count }}</td>
                        <td class="small text-body-secondary">
                            Mispronunciations, substitutions, omissions, insertions, repetitions and words not reached.
                            Self-corrections, hesitations and pauses are shown but are <em>not</em> counted as errors.
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Model confidence</th>
                        <td class="fw-semibold text-nowrap">{{ $hrConfidence !== null ? $hrConfidence . '%' : 'None' }}</td>
                        <td class="small text-body-secondary">
                            How strongly the model's trees agreed on the suggested skill. It is
                            <strong>not</strong> the chance that the suggestion is right. A high figure on a poor
                            recording is still a poor result, so read it together with the checks above.
                        </td>
                    </tr>
                    @if($hrTestAcc)
                        <tr>
                            <th scope="row">Model accuracy</th>
                            <td class="fw-semibold text-nowrap">{{ $hrN((float) $hrTestAcc) }}%</td>
                            <td class="small text-body-secondary">
                                How often the model matched teachers' labels on held-out clips during development.
                                It means about four suggestions in ten can disagree with a teacher, which is why
                                the teacher confirms every result.
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>

        {{-- 3. FAQ --}}
        <h6 class="small text-uppercase text-muted fw-semibold mb-2">Frequently asked questions</h6>
        <div class="accordion" id="resultFaq">
            @php
                $faq = [
                    ['Is the AI deciding the learner\'s level?',
                        'No. The reading level comes from counting: accuracy and, when asked, the comprehension score, compared with the Phil-IRI cut-offs. The AI only suggests which skill looks weakest, and you confirm, override or invalidate it.'],
                    ['What is the difference between the level and the weakness?',
                        'The level says how hard this passage was for the learner (independent, instructional or frustration). The weakness says which skill is most likely behind the difficulty: phonemic awareness, decoding, fluency or comprehension.'],
                    ['What does "No significant weakness detected" mean?',
                        'The measured numbers showed no skill below its cut-off. It is not a guarantee: it describes this one reading of one passage.'],
                    ['Why does the page say "Can\'t tell" for a skill?',
                        'There was not enough evidence to judge it. Comprehension is the usual case: it can only be judged when questions were answered. The system shows "Can\'t tell" instead of guessing.'],
                    ['The model is confident but the numbers look fine. Which do I trust?',
                        'Trust the measured numbers and your own listening. Confidence only says the trees agreed. If the evidence does not support the pick the page marks it as weak, and you can override it.'],
                    ['Why were some words marked as mispronounced when the child said them correctly?',
                        'The speech model can mishear a word, especially with noise, a soft voice or Filipino-English mixing. Words the engine was unsure about carry a wavy underline. Play the recording, and use the teacher verdict to correct the score.'],
                    ['Why is the words-per-minute figure different from my stopwatch?',
                        'Only correctly read words are counted, and the time is the whole length of the recording. Silence before or after the reading, or a long pause, lowers the figure, and so does every miscue.'],
                    ['What if the recording was noisy or the child stopped early?',
                        'Look at "What to check before you decide" in the AI/ML Analysis card. It flags poor audio, a short reading and an unreached passage. In those cases re-assess or use the teacher verdict rather than relying on the figures.'],
                    ['Does the AI work the same for Filipino and English?',
                        'Not equally. The model was trained on Grades 3&ndash;4 clips, with more Filipino than English, and the speech model is a stock one that has not been tuned for classroom audio. Be more careful with English results and with other grades.'],
                    ['What happens to my override?',
                        'The AI\'s original values are stored beside your decision. Your decision is what shows as the learner\'s record, and the original stays visible for audit.'],
                ];
            @endphp
            @foreach($faq as $i => [$q, $a])
                <div class="accordion-item">
                    <h2 class="accordion-header" id="faqH{{ $i }}">
                        <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse"
                                data-bs-target="#faqC{{ $i }}" aria-expanded="false" aria-controls="faqC{{ $i }}">
                            {{ $q }}
                        </button>
                    </h2>
                    <div id="faqC{{ $i }}" class="accordion-collapse collapse" aria-labelledby="faqH{{ $i }}" data-bs-parent="#resultFaq">
                        <div class="accordion-body small text-body-secondary">{!! $a !!}</div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
