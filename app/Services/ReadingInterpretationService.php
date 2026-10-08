<?php

namespace App\Services;

use App\Models\Assessment;

/**
 * Explains, in the teacher's terms, why a reading landed at its level.
 *
 * A bare "Frustration" label tells a teacher nothing they can act on. This
 * lays out the rule that produced it, the numbers that fed the rule, which
 * miscues did the damage (with the actual words), how close the child was to
 * the next level, and anything that should make the teacher doubt the label.
 *
 * Built when the page is shown rather than stored, so it always reflects the
 * teacher's current verdict and works for results analysed before it existed.
 */
class ReadingInterpretationService
{
    private const LABELS = [
        'independent' => 'Independent',
        'instructional' => 'Instructional',
        'frustration' => 'Frustration',
    ];

    private const MEANING = [
        'independent' => 'The learner can read this passage alone, without help.',
        'instructional' => 'The learner can read this passage with a teacher\'s guidance — the right level to teach at.',
        'frustration' => 'This passage is too hard for the learner right now; reading it breaks down.',
    ];

    private const MISCUE_NAMES = [
        'mispronunciation' => 'mispronounced',
        'substitution' => 'replaced with another word',
        'omission' => 'skipped',
        'insertion' => 'added',
        'repetition' => 'repeated',
    ];

    public function for(Assessment $assessment): array
    {
        $result = $assessment->result;

        if (!$result) {
            return [];
        }

        $ml = $result->ml_analysis_json ?? [];
        $verdict = $assessment->verdict;

        $measuredLevel = $result->reading_level;
        $level = $assessment->effectiveReadingLevel() ?? $measuredLevel;
        $accuracy = (float) ($assessment->effectiveAccuracy() ?? 0);

        $totalWords = (int) ($verdict?->words_read ?: ($ml['total_words'] ?? 0));
        $correctWords = $totalWords > 0 ? (int) round($accuracy / 100 * $totalWords) : 0;

        $unusable = $this->unusableReason($ml);

        if ($unusable && !$verdict?->isOverridden()) {
            // Nothing was actually measured. Explaining the child's "skipped
            // words" or how close they came to the next level would dress up
            // noise as a diagnosis, so say only what is true.
            $reasons = [
                $unusable,
                $this->accuracyReason($level, $measuredLevel, $accuracy, $totalWords, $correctWords, $verdict),
            ];
        } else {
            $reasons = array_values(array_filter([
                $this->accuracyReason($level, $measuredLevel, $accuracy, $totalWords, $correctWords, $verdict),
                $this->stoppedEarlyReason($ml),
                $this->distanceReason(PhilIriLevels::wordReading($accuracy), $totalWords, $correctWords),
                $this->miscueReason($ml, $result),
                $this->fluencyReason($ml, $assessment),
                $this->comprehensionReason($assessment, $level),
                $this->reliabilityReason($ml),
            ]));
        }

        return [
            'level' => $level,
            'label' => self::LABELS[$level] ?? ucfirst((string) $level),
            'meaning' => $unusable && !$verdict?->isOverridden()
                ? 'This label was produced by the scoring rule, but it does not describe the learner — see below.'
                : (self::MEANING[$level] ?? ''),
            'rule' => sprintf(
                'Phil-IRI word reading: Independent at %d%% and above, Instructional from %d%% to %d%%, Frustration below %d%%.',
                PhilIriLevels::WORD_INDEPENDENT_FROM,
                PhilIriLevels::WORD_INSTRUCTIONAL_FROM,
                PhilIriLevels::WORD_INDEPENDENT_FROM - 1,
                PhilIriLevels::WORD_INSTRUCTIONAL_FROM
            ),
            'accuracy' => $accuracy,
            'scale' => $this->scale($accuracy),
            'reasons' => $reasons,
        ];
    }

    private function accuracyReason(string $level, ?string $measuredLevel, float $accuracy, int $totalWords, int $correctWords, $verdict): array
    {
        $text = $totalWords > 0
            ? sprintf('%d of %d words were read correctly — %s%% accuracy.', $correctWords, $totalWords, $this->number($accuracy))
            : sprintf('Accuracy was %s%%.', $this->number($accuracy));

        $byRule = PhilIriLevels::wordReading($accuracy);

        if ($verdict?->isOverridden() && $verdict->final_reading_level && $byRule !== $level) {
            $text .= sprintf(
                ' On the numbers alone that is %s; the teacher set it to %s.',
                self::LABELS[$byRule] ?? $byRule,
                self::LABELS[$level] ?? $level
            );
        } else {
            $text .= ' ' . match ($level) {
                'independent' => sprintf('That is at or above the %d%% needed for Independent.', PhilIriLevels::WORD_INDEPENDENT_FROM),
                'instructional' => sprintf('That is inside the %d–%d%% Instructional band.', PhilIriLevels::WORD_INSTRUCTIONAL_FROM, PhilIriLevels::WORD_INDEPENDENT_FROM - 1),
                default => sprintf('That is below the %d%% needed for Instructional.', PhilIriLevels::WORD_INSTRUCTIONAL_FROM),
            };
        }

        return ['title' => 'Word accuracy', 'text' => $text, 'tone' => $this->toneFor($level)];
    }

    /** The recording holds nothing to measure, so no level can be read from it. */
    private function unusableReason(array $ml): ?array
    {
        if ((($ml['provenance'] ?? [])['stt_engine'] ?? null) === 'mock') {
            $why = 'No speech engine was running when this was analysed, so placeholder text was scored instead of the child\'s voice.';
        } elseif ((($ml['audio_quality'] ?? [])['rating'] ?? null) === 'no_speech') {
            $why = 'No speech was detected anywhere in the recording, so there was no reading to score.';
        } else {
            return null;
        }

        return [
            'title' => 'This is not a measurement of the learner',
            'tone' => 'bad',
            'callout' => true,
            'text' => $why . ' The level and every number below come from that empty input. Mark the result invalid and re-assess.',
        ];
    }

    /**
     * Where the accuracy sits among the three Phil-IRI bands, as 0-100% along a
     * bar. The bands are very different widths in real percentages (90, 7 and 3
     * points), so each gets a third of the bar and the marker moves within its
     * own band — otherwise Instructional and Independent would be slivers.
     */
    private function scale(float $accuracy): array
    {
        $instructional = PhilIriLevels::WORD_INSTRUCTIONAL_FROM;
        $independent = PhilIriLevels::WORD_INDEPENDENT_FROM;
        $third = 100 / 3;

        $position = match (true) {
            $accuracy >= $independent => 2 * $third + ($accuracy - $independent) / (100 - $independent) * $third,
            $accuracy >= $instructional => $third + ($accuracy - $instructional) / ($independent - $instructional) * $third,
            default => max(0.0, $accuracy) / $instructional * $third,
        };

        return [
            'position' => round(min(100, max(0, $position)), 1),
            // The band the numbers alone put the learner in. Differs from the
            // shown level only when the teacher overrode it.
            'by_numbers' => PhilIriLevels::wordReading($accuracy),
            'bands' => [
                ['key' => 'frustration', 'label' => 'Frustration', 'range' => sprintf('below %d%%', $instructional)],
                ['key' => 'instructional', 'label' => 'Instructional', 'range' => sprintf('%d–%d%%', $instructional, $independent - 1)],
                ['key' => 'independent', 'label' => 'Independent', 'range' => sprintf('%d%% and above', $independent)],
            ],
        ];
    }

    /**
     * The reading (or the recording) ended before the passage did. The level
     * still counts the unread words, so show what the child managed on the part
     * they actually attempted.
     */
    private function stoppedEarlyReason(array $ml): ?array
    {
        $notReached = (int) ($ml['miscues']['not_reached'] ?? 0);
        $total = (int) ($ml['total_words'] ?? 0);

        if ($notReached === 0 || $total === 0) {
            return null;
        }

        $attempted = $total - $notReached;
        $correct = (int) ($ml['miscues']['correct'] ?? 0);
        $lastWord = $this->lastWordAttempted($ml['word_comparison'] ?? []);

        $text = sprintf(
            'The reading stopped%s — the last %d of %d words were never attempted, and they count as unread.',
            $lastWord ? sprintf(' after "%s"', $lastWord) : '',
            $notReached,
            $total
        );

        if ($attempted > 0) {
            $text .= sprintf(
                ' On the %d words that were attempted, %d were correct (%s%%), which on its own would be %s.',
                $attempted,
                $correct,
                $this->number($correct / $attempted * 100),
                self::LABELS[PhilIriLevels::wordReading($correct / $attempted * 100)] ?? ''
            );
        }

        $text .= ' Check whether the child gave up or the recording was cut short — if it was cut short, re-assess rather than accept this level.';

        return ['title' => 'Stopped before the end', 'tone' => 'warn', 'text' => $text];
    }

    private function lastWordAttempted(array $comparison): ?string
    {
        $last = null;

        foreach ($comparison as $entry) {
            if (($entry['spoken'] ?? null) !== null && ($entry['reference'] ?? null) !== null) {
                $last = $entry['reference'];
            }
        }

        return $last;
    }

    /** How many words separated the child from the level above (or below). */
    private function distanceReason(?string $levelByNumbers, int $totalWords, int $correctWords): ?array
    {
        if ($totalWords <= 0 || !$levelByNumbers) {
            return null;
        }

        $neededFor = fn (float $percent) => (int) ceil($percent / 100 * $totalWords);

        if ($levelByNumbers === 'frustration') {
            $short = $neededFor(PhilIriLevels::WORD_INSTRUCTIONAL_FROM) - $correctWords;

            return ['title' => 'Distance to the next level', 'tone' => 'neutral', 'text' => sprintf(
                '%d more word%s read correctly would have reached Instructional on this passage.',
                $short, $short === 1 ? '' : 's'
            )];
        }

        if ($levelByNumbers === 'instructional') {
            $short = $neededFor(PhilIriLevels::WORD_INDEPENDENT_FROM) - $correctWords;
            $spare = $correctWords - $neededFor(PhilIriLevels::WORD_INSTRUCTIONAL_FROM);

            return ['title' => 'Distance to the next level', 'tone' => 'neutral', 'text' => sprintf(
                '%d more word%s read correctly would have reached Independent; %d more miscue%s would have dropped it to Frustration.',
                $short, $short === 1 ? '' : 's', $spare + 1, $spare + 1 === 1 ? '' : 's'
            )];
        }

        $spare = $correctWords - $neededFor(PhilIriLevels::WORD_INDEPENDENT_FROM);

        return ['title' => 'Margin', 'tone' => 'neutral', 'text' => sprintf(
            'The learner could have made %d more miscue%s and still been Independent on this passage.',
            $spare, $spare === 1 ? '' : 's'
        )];
    }

    /** Which miscues cost the accuracy, with the words themselves. */
    private function miscueReason(array $ml, $result): ?array
    {
        $counts = $ml['miscues'] ?? [
            // Results analysed before the finer breakdown existed.
            'mispronunciation' => 0,
            'substitution' => (int) $result->substitutions,
            'omission' => (int) $result->omissions,
            'insertion' => (int) $result->insertions,
            'repetition' => (int) $result->repetitions,
        ];

        $scored = array_filter(array_intersect_key($counts, self::MISCUE_NAMES));

        if (empty($scored)) {
            return ['title' => 'Miscues', 'tone' => 'good', 'text' => 'No miscues were recorded.'];
        }

        arsort($scored);
        $examples = $this->examples($ml['word_comparison'] ?? []);

        $parts = [];
        foreach ($scored as $type => $count) {
            $part = sprintf('%d %s', $count, self::MISCUE_NAMES[$type]);

            if (!empty($examples[$type])) {
                $part .= ' (' . implode(', ', array_slice($examples[$type], 0, 3)) . ')';
            }

            $parts[] = $part;
        }

        $top = array_key_first($scored);
        $hint = match ($top) {
            'mispronunciation' => 'Most slips were near-misses of the right word, which points at decoding the sounds rather than not knowing the word.',
            'substitution' => 'Most slips swapped in a different word, which often means guessing from context or the first letter.',
            'omission' => 'Most slips were skipped words, which can mean rushing, losing the place, or avoiding words that look hard.',
            'insertion' => 'Most slips were added words, which usually means reading ahead of the text from expectation.',
            'repetition' => 'Most slips were repeats, which usually means the learner is buying time to work out the next word.',
            default => '',
        };

        return [
            'title' => 'What went wrong',
            'tone' => 'neutral',
            'text' => ucfirst(implode('; ', $parts)) . '. ' . $hint,
        ];
    }

    private function fluencyReason(array $ml, Assessment $assessment): ?array
    {
        $counts = $ml['miscues'] ?? [];
        $wpm = $assessment->effectiveWordsPerMinute();

        $parts = [];

        if ($wpm !== null) {
            $parts[] = sprintf('Read at %s words per minute', $this->number((float) $wpm, 0));
        }

        $hesitations = (int) ($counts['hesitation'] ?? 0);
        $longPauses = (int) ($counts['long_pause'] ?? 0);

        if ($hesitations || $longPauses) {
            $where = $this->pauseWords($ml['word_comparison'] ?? []);
            $pause = [];

            if ($hesitations) {
                $pause[] = sprintf('%d hesitation%s of a second or more', $hesitations, $hesitations === 1 ? '' : 's');
            }
            if ($longPauses) {
                $pause[] = sprintf('%d long pause%s of three seconds or more', $longPauses, $longPauses === 1 ? '' : 's');
            }

            $parts[] = 'with ' . implode(' and ', $pause) . ($where ? ' (before ' . implode(', ', $where) . ')' : '');
        } elseif (isset($counts['hesitation'])) {
            $parts[] = 'with no noticeable pauses';
        }

        $text = $parts ? implode(' ', $parts) . '.' : '';

        $selfCorrections = (int) ($counts['self_correction'] ?? 0);

        if ($selfCorrections > 0) {
            $text .= sprintf(
                ' The learner caught and fixed %d of their own mistake%s — that is not counted against them, and it shows they are checking that what they read makes sense.',
                $selfCorrections, $selfCorrections === 1 ? '' : 's'
            );
        }

        return $text === '' ? null : ['title' => 'Fluency', 'text' => trim($text), 'tone' => 'neutral'];
    }

    private function comprehensionReason(Assessment $assessment, string $level): ?array
    {
        $score = $assessment->comprehensionScore();

        if ($score === null) {
            return null;
        }

        $comprehensionLevel = PhilIriLevels::comprehension($score);
        $text = sprintf(
            'Answered %s%% of the comprehension questions correctly, which on its own is the %s level.',
            $this->number($score),
            self::LABELS[$comprehensionLevel] ?? $comprehensionLevel
        );

        if ($comprehensionLevel !== $level) {
            $text .= sprintf(
                ' The level shown above comes from word reading only, so the two disagree — weigh both before settling on %s.',
                self::LABELS[$level] ?? $level
            );
        }

        return ['title' => 'Comprehension', 'text' => $text, 'tone' => $this->toneFor($comprehensionLevel)];
    }

    /** Reasons to doubt the label that have nothing to do with the child's reading. */
    private function reliabilityReason(array $ml): ?array
    {
        $quality = $ml['audio_quality'] ?? null;
        $provenance = $ml['provenance'] ?? [];
        $nonReading = $ml['non_reading'] ?? [];

        $doubts = [];

        if (($provenance['stt_engine'] ?? null) === 'mock') {
            $doubts[] = 'no speech engine was running, so this was not measured from the child\'s voice at all';
        }

        if (($quality['rating'] ?? null) === 'no_speech') {
            $doubts[] = 'no speech was detected in the recording';
        } elseif (($quality['rating'] ?? null) === 'noisy') {
            $doubts[] = sprintf('the recording was noisy (signal only %s dB above the background)', $this->number((float) ($quality['snr_db'] ?? 0)));
        }

        if (!empty($nonReading['unreliable_segments'])) {
            $doubts[] = 'part of the transcript was probably written over noise rather than speech';
        }

        if (($ml['miscues']['unclear'] ?? 0) >= 3) {
            $doubts[] = sprintf('%d words were too unclear for the speech engine to be sure of', $ml['miscues']['unclear']);
        }

        if (empty($doubts)) {
            return null;
        }

        return [
            'title' => 'Before you trust this level',
            'tone' => 'warn',
            'callout' => true,
            'text' => ucfirst(implode('; ', $doubts)) . '. A low score here may describe the recording, not the reader.',
        ];
    }

    // ── Why this weakness ─────────────────────────────────────────────

    /** Plain wording for what each skill is, for teachers who don't use the technical names. */
    private const SKILL_PLAIN = [
        1 => 'Matching letters to sounds',
        2 => 'Reading words correctly',
        3 => 'Pace and smoothness',
        4 => 'Understanding the text',
    ];

    /**
     * What the reading shows about each of the four skills, and why the model's
     * pick fits (or doesn't fit) that evidence.
     *
     * The model's classifier is a Random Forest and cannot say which of its own
     * inputs decided a case, so this does not claim to replay its reasoning.
     * It checks the measurable signs for each skill directly, then says whether
     * those signs support what the model chose.
     */
    public function weakness(Assessment $assessment): array
    {
        $result = $assessment->result;

        if (!$result) {
            return [];
        }

        $ml = $result->ml_analysis_json ?? [];
        $labels = config('bigkas.weakness_categories', []);
        $usable = $this->unusableReason($ml) === null;

        $skills = $this->skillSigns($assessment, $ml, $result, $usable);

        $picked = $result->primary_weakness;
        $confidence = $result->confidence_score;

        $primary = $this->primaryExplanation($picked, $skills, $labels, $usable);
        $primary['confidence_note'] = ($confidence !== null && $confidence < 0.6)
            ? sprintf('The model itself was not very sure about this (%d%%), so treat it as a suggestion.', round($confidence * 100))
            : null;

        $finalNote = null;
        $final = $assessment->effectivePrimaryWeakness();
        if ($assessment->verdict?->isOverridden() && $final !== $picked) {
            $finalNote = sprintf(
                'You set the weakness to %s (the model said %s).',
                $this->weaknessName($final, $labels),
                $this->weaknessName($picked, $labels)
            );
        }

        return ['primary' => $primary, 'skills' => array_values($skills), 'final_note' => $finalNote];
    }

    private function primaryExplanation(?int $picked, array $skills, array $labels, bool $usable): array
    {
        if (!$usable) {
            return [
                'id' => $picked,
                'name' => $this->weaknessName($picked, $labels),
                'headline' => 'Why this classification?',
                'fit' => 'unusable',
                'text' => 'No usable speech was recorded, so this classification is not based on the learner.',
            ];
        }

        if ($picked === null || $picked === 0) {
            $concern = collect($skills)->firstWhere('status', 'concern');

            return [
                'id' => 0,
                'name' => 'No weakness',
                'headline' => 'Why no weakness was flagged',
                'fit' => $concern ? 'weak' : 'supported',
                'text' => $concern
                    ? sprintf('The model flagged no weakness, but this reading does show a sign of a problem with %s: %s', strtolower($concern['plain']), $concern['text'])
                    : 'None of the four skills shows a clear sign of a problem in this reading.',
            ];
        }

        $skill = $skills[$picked];
        $name = $this->weaknessName($picked, $labels);
        $description = lcfirst($labels[$picked]['description'] ?? '');

        if (in_array($skill['status'], ['concern', 'watch'], true)) {
            return [
                'id' => $picked,
                'name' => $name,
                'headline' => "Why {$name}?",
                'fit' => 'supported',
                'text' => trim($skill['text'] . ($description ? " That fits {$name} — {$description}." : '')),
            ];
        }

        if ($skill['status'] === 'unknown') {
            return [
                'id' => $picked,
                'name' => $name,
                'headline' => "Why {$name}?",
                'fit' => 'weak',
                'text' => sprintf('The model chose %s. %s So this is a guess, not something measured.', $name, $skill['text']),
            ];
        }

        $text = sprintf('The model chose %s, but this reading shows little direct sign of it. %s', $name, $skill['text']);

        $stronger = collect($skills)->first(fn ($s) => $s['id'] !== $picked && $s['status'] === 'concern');
        if ($stronger) {
            $text .= sprintf(' The clearer sign is with %s: %s', strtolower($stronger['plain']), $stronger['text']);
        }

        return ['id' => $picked, 'name' => $name, 'headline' => "Why {$name}?", 'fit' => 'weak', 'text' => $text];
    }

    /**
     * One row per skill: how the reading looks for it, in a sentence.
     *
     * status: concern (a sign of a problem), watch (worth keeping an eye on),
     * ok (no sign), unknown (not enough information to say).
     */
    private function skillSigns(Assessment $assessment, array $ml, $result, bool $usable): array
    {
        $labels = config('bigkas.weakness_categories', []);
        $counts = $this->miscueCounts($ml, $result);
        $examples = $this->examples($ml['word_comparison'] ?? []);

        $total = (int) ($ml['total_words'] ?? 0);
        $attempted = max(0, $total - $counts['not_reached']);
        $correct = $counts['correct'] ?: (int) round(((float) $result->accuracy_rate) / 100 * $total);
        $slips = $counts['mispronunciation'] + $counts['substitution'] + $counts['omission']
            + $counts['insertion'] + $counts['repetition'];

        $row = fn (int $id, string $status, string $text) => [
            'id' => $id,
            'name' => $labels[$id]['name'] ?? "Skill {$id}",
            'plain' => self::SKILL_PLAIN[$id],
            'status' => $status,
            'text' => $text,
        ];

        $noSpeech = 'There was no usable speech in this recording, so this can\'t be judged.';

        // 1. Matching letters to sounds: slips that sound like the right word.
        $soundAlike = $counts['mispronunciation'];
        $share = $slips > 0 ? $soundAlike / $slips : 0;
        $example = !empty($examples['mispronunciation']) ? ' (' . implode(', ', array_slice($examples['mispronunciation'], 0, 2)) . ')' : '';

        if (!$usable) {
            $phonemic = $row(1, 'unknown', $noSpeech);
        } elseif ($soundAlike >= 3 && $share >= 0.4) {
            $phonemic = $row(1, 'concern', sprintf('%d of %d slips sounded like the right word%s. That pattern points to trouble matching letters to sounds.', $soundAlike, $slips, $example));
        } elseif ($soundAlike >= 1 && $share >= 0.4) {
            $phonemic = $row(1, 'watch', sprintf('%d of %d slips sounded like the right word%s — a small hint of trouble with letter sounds.', $soundAlike, $slips, $example));
        } elseif ($slips === 0) {
            $phonemic = $row(1, 'ok', 'No words were misread, so there are no sound mix-ups.');
        } else {
            $phonemic = $row(1, 'ok', sprintf('Only %d of %d slips sounded like the right word — no sound-mixing pattern.', $soundAlike, $slips));
        }

        // 2. Reading words correctly: accuracy on the words actually attempted,
        //    so stopping early isn't mistaken for not being able to decode.
        if (!$usable) {
            $decoding = $row(2, 'unknown', $noSpeech);
        } elseif ($attempted === 0) {
            $decoding = $row(2, 'unknown', 'No words were attempted, so this can\'t be judged.');
        } else {
            $attemptedAccuracy = $correct / $attempted * 100;
            $note = $counts['not_reached'] > 0
                ? sprintf(' The %d words never reached are not counted here.', $counts['not_reached'])
                : '';

            $base = sprintf('%d of the %d words attempted were read correctly (%s%%)', $correct, $attempted, $this->number($attemptedAccuracy));

            $decoding = match (true) {
                $attemptedAccuracy < PhilIriLevels::WORD_INSTRUCTIONAL_FROM => $row(2, 'concern', sprintf('%s — below the %d%% needed even for Instructional.%s', $base, PhilIriLevels::WORD_INSTRUCTIONAL_FROM, $note)),
                $attemptedAccuracy < PhilIriLevels::WORD_INDEPENDENT_FROM => $row(2, 'watch', sprintf('%s — in the Instructional range, with %d wrong.%s', $base, $attempted - $correct, $note)),
                default => $row(2, 'ok', $base . '.' . $note),
            };
        }

        // 3. Pace and smoothness: words per minute against the grade benchmark,
        //    plus pauses and repeats.
        $wpm = (float) ($assessment->effectiveWordsPerMinute() ?? 0);
        $grade = (int) $assessment->learner?->grade_level;
        $bench = config("bigkas.wpm_benchmarks.{$grade}");
        $longPauses = $counts['long_pause'];
        $hesitations = $counts['hesitation'];
        $repeats = $counts['repetition'];

        if (!$usable) {
            $fluency = $row(3, 'unknown', $noSpeech);
        } else {
            $rank = ['ok' => 0, 'watch' => 1, 'concern' => 2];
            $status = 'ok';
            $raise = function (string $to) use (&$status, $rank) {
                if ($rank[$to] > $rank[$status]) {
                    $status = $to;
                }
            };

            if ($bench) {
                $raise($wpm < $bench['min'] ? 'concern' : ($wpm < $bench['target'] ? 'watch' : 'ok'));
            }
            if ($longPauses >= 3) {
                $raise('concern');
            } elseif ($longPauses >= 1 || $hesitations >= 3) {
                $raise('watch');
            }
            if ($repeats >= 3) {
                $raise('watch');
            }

            $parts = [sprintf(
                'Read at %s words per minute%s.',
                $this->number($wpm, 0),
                $bench ? sprintf('; a Grade %d reader is expected to reach about %d', $grade, $bench['target']) : ''
            )];

            if ($longPauses || $hesitations) {
                $bits = [];
                if ($longPauses) {
                    $bits[] = sprintf('%d long pause%s', $longPauses, $longPauses === 1 ? '' : 's');
                }
                if ($hesitations) {
                    $bits[] = sprintf('%d hesitation%s', $hesitations, $hesitations === 1 ? '' : 's');
                }
                $where = $this->pauseWords($ml['word_comparison'] ?? []);
                $parts[] = ucfirst(implode(' and ', $bits)) . ($where ? ' (before ' . implode(', ', $where) . ')' : '') . '.';
            } elseif (isset($ml['miscues']['hesitation'])) {
                $parts[] = 'No noticeable pauses.';
            }

            if ($repeats) {
                $parts[] = sprintf('Repeated %d word%s.', $repeats, $repeats === 1 ? '' : 's');
            }

            $fluency = $row(3, $status, implode(' ', $parts));
        }

        // 4. Understanding the text: only the comprehension questions measure
        //    this directly, and they don't depend on the audio.
        $score = $assessment->comprehensionScore();

        if ($score === null) {
            $comprehension = $row(4, 'unknown', 'No comprehension questions were asked, so this can\'t be checked directly. The model can only guess it from how the reading went.');
        } else {
            $answers = $assessment->comprehensionAnswers;
            $right = $answers->where('is_correct', true)->count();

            $status = ['independent' => 'ok', 'instructional' => 'watch', 'frustration' => 'concern'][PhilIriLevels::comprehension($score)];
            $comprehension = $row(4, $status, sprintf('%d of %d comprehension questions were answered correctly (%s%%).', $right, $answers->count(), $this->number($score)));
        }

        return [1 => $phonemic, 2 => $decoding, 3 => $fluency, 4 => $comprehension];
    }

    private function weaknessName(?int $id, array $labels): string
    {
        return ($id === null || $id === 0) ? 'no weakness' : ($labels[$id]['name'] ?? "weakness {$id}");
    }

    /** Miscue counts, falling back to the old columns for results analysed before the finer breakdown. */
    private function miscueCounts(array $ml, $result): array
    {
        $counts = array_fill_keys([
            'correct', 'mispronunciation', 'substitution', 'omission', 'not_reached', 'insertion',
            'repetition', 'self_correction', 'off_passage', 'hesitation', 'long_pause', 'unclear',
        ], 0);

        if (isset($ml['miscues'])) {
            return array_merge($counts, $ml['miscues']);
        }

        return array_merge($counts, [
            'substitution' => (int) $result->substitutions,
            'omission' => (int) $result->omissions,
            'insertion' => (int) $result->insertions,
            'repetition' => (int) $result->repetitions,
            'self_correction' => (int) $result->self_corrections,
        ]);
    }

    /** A few real examples of each miscue, as the teacher would write them. */
    private function examples(array $comparison): array
    {
        $examples = [];

        foreach ($comparison as $entry) {
            $type = $entry['miscue'] ?? $entry['status'] ?? null;

            $examples[$type][] = match ($type) {
                'mispronunciation', 'substitution' => sprintf('"%s" read as "%s"', $entry['reference'], $entry['spoken']),
                'omission' => sprintf('"%s"', $entry['reference']),
                default => sprintf('"%s"', $entry['spoken'] ?? $entry['reference']),
            };
        }

        return array_map('array_unique', $examples);
    }

    private function pauseWords(array $comparison): array
    {
        $words = [];

        foreach ($comparison as $entry) {
            if (isset($entry['pause_kind'])) {
                $words[] = sprintf('"%s"', $entry['reference'] ?? $entry['spoken']);
            }
        }

        return array_slice(array_unique($words), 0, 4);
    }

    private function toneFor(?string $level): string
    {
        return match ($level) {
            'independent' => 'good',
            'instructional' => 'ok',
            'frustration' => 'bad',
            default => 'neutral',
        };
    }

    private function number(float $value, int $decimals = 1): string
    {
        $formatted = number_format($value, $decimals);

        // Trim "90.0" to "90", but never touch the zeros of a whole number like "70".
        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }
}
