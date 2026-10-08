<?php

namespace App\Services;

/**
 * Turns a raw word alignment into the miscues a reading teacher looks for.
 *
 * The alignment only knows three things about a word: it matched, it was
 * replaced, or it has no partner. That is not enough for an oral reading
 * record, where a wrong word the child immediately fixes (self-correction), a
 * word said twice (repetition) and a sentence spoken to the teacher (not
 * reading at all) all show up as the same "extra word".
 *
 * Every entry keeps the original four-way `status` so existing consumers are
 * unaffected, and gains a finer `miscue`:
 *
 *   correct          read as written
 *   mispronunciation replaced by a word that sounds like the target
 *   substitution     replaced by an unrelated word
 *   omission         skipped
 *   not_reached      never attempted: the reading stopped before this word
 *   insertion        an extra word added while reading
 *   repetition       a word or phrase said again, or a false start ("ma- mabilis")
 *   self_correction  a wrong attempt the child then fixed — not a miscue
 *   off_passage      speech that is not the passage (talking, a title, another voice)
 *
 * Limits worth knowing: this works on what the speech engine wrote down. If
 * the engine silently tidied a stumble away, it cannot be recovered here, and
 * "mispronunciation" means "sounds like the target word" — without a
 * pronunciation model it cannot tell a slip of the tongue from a wrong guess.
 */
class MiscueClassifierService
{
    /** A replaced word this similar to the target is treated as an attempt at it. */
    private const SOUND_ALIKE_SIMILARITY = 0.5;

    /** Gaps between words, in seconds. */
    private const HESITATION_SECONDS = 1.0;
    private const LONG_PAUSE_SECONDS = 3.0;

    /** Below this the speech engine was guessing at the word. */
    private const UNCLEAR_CONFIDENCE = 0.5;

    /** Consecutive extra words before they're treated as talk rather than reading. */
    private const OFF_PASSAGE_RUN_EDGE = 3;
    private const OFF_PASSAGE_RUN_MIDDLE = 4;

    /** How far either side to look for the word being repeated. */
    private const REPETITION_WINDOW = 2;

    /** Unread words at the end before it's "stopped early" rather than a dropped last word. */
    private const NOT_REACHED_MIN_WORDS = 3;

    /**
     * @param  array  $alignment     pairs of ['reference' => ?string, 'spoken' => ?string]
     * @param  array  $spokenTokens  one per spoken word, in order:
     *                               ['word', 'start', 'end', 'confidence'] (timing optional)
     */
    public function classify(array $alignment, array $spokenTokens = []): array
    {
        $details = $this->attachSpokenTokens(array_values($alignment), $spokenTokens);

        $this->markNotReached($details);
        $this->markOffPassageAndPhraseRepetitions($details);
        $this->markSingleInsertions($details);
        $this->markReplacements($details);
        $this->markPauses($details);

        return [
            'details' => $details,
            'counts' => $this->count($details),
            'off_passage' => $this->offPassageSegments($details),
        ];
    }

    /** Base status plus whatever the speech engine told us about each spoken word. */
    private function attachSpokenTokens(array $alignment, array $spokenTokens): array
    {
        $details = [];
        $spokenIndex = 0;

        foreach ($alignment as $pair) {
            $reference = $pair['reference'] ?? null;
            $spoken = $pair['spoken'] ?? null;

            $status = match (true) {
                $reference === null => 'insertion',
                $spoken === null => 'omission',
                $reference === $spoken => 'correct',
                default => 'substitution',
            };

            $entry = [
                'reference' => $reference,
                'spoken' => $spoken,
                'status' => $status,
                'miscue' => $status,
            ];

            if ($spoken !== null) {
                $token = $spokenTokens[$spokenIndex++] ?? [];

                foreach (['start', 'end', 'confidence'] as $key) {
                    if (isset($token[$key])) {
                        $entry[$key] = round((float) $token[$key], 2);
                    }
                }

                if (isset($entry['confidence']) && $entry['confidence'] < self::UNCLEAR_CONFIDENCE) {
                    $entry['unclear'] = true;
                }
            }

            $details[] = $entry;
        }

        return $details;
    }

    /**
     * Words after the last thing the child said were never attempted — the
     * child stopped, or the recording did. That is a different fact from
     * skipping a word mid-sentence, and the teacher needs to see which it was.
     * (They still count against accuracy; this only names them honestly.)
     */
    private function markNotReached(array &$details): void
    {
        $lastSpoken = $this->lastIndexWhere($details, fn ($d) => $d['spoken'] !== null);
        $firstUnread = $lastSpoken === null ? 0 : $lastSpoken + 1;

        if (count($details) - $firstUnread < self::NOT_REACHED_MIN_WORDS) {
            return;
        }

        for ($k = $firstUnread; $k < count($details); $k++) {
            $details[$k]['miscue'] = 'not_reached';
        }
    }

    /**
     * Long runs of extra words are either the child re-reading a phrase, or
     * not reading at all. Decide which before looking at words one by one.
     */
    private function markOffPassageAndPhraseRepetitions(array &$details): void
    {
        $count = count($details);
        $firstRead = $this->firstIndexWhere($details, fn ($d) => $d['status'] !== 'insertion');
        $lastRead = $this->lastIndexWhere($details, fn ($d) => $d['status'] !== 'insertion');

        $i = 0;
        while ($i < $count) {
            if ($details[$i]['status'] !== 'insertion') {
                $i++;
                continue;
            }

            $start = $i;
            while ($i < $count && $details[$i]['status'] === 'insertion') {
                $i++;
            }
            $end = $i - 1;
            $length = $end - $start + 1;

            $atEdge = $firstRead === null || $end < $firstRead || $start > $lastRead;
            $needed = $atEdge ? self::OFF_PASSAGE_RUN_EDGE : self::OFF_PASSAGE_RUN_MIDDLE;

            if ($length < $needed) {
                continue; // short runs are judged word by word
            }

            $miscue = $this->isRereadPhrase($details, $start, $end) ? 'repetition' : 'off_passage';

            for ($k = $start; $k <= $end; $k++) {
                $details[$k]['miscue'] = $miscue;
            }
        }
    }

    /** Most of the run's words were just read (or are about to be) nearby. */
    private function isRereadPhrase(array $details, int $start, int $end): bool
    {
        $length = $end - $start + 1;
        $window = $length + 2;

        $nearby = [];
        for ($k = max(0, $start - $window); $k <= min(count($details) - 1, $end + $window); $k++) {
            if (($k < $start || $k > $end) && $details[$k]['status'] !== 'insertion' && $details[$k]['spoken'] !== null) {
                $nearby[$details[$k]['spoken']] = true;
            }
        }

        $matched = 0;
        for ($k = $start; $k <= $end; $k++) {
            if (isset($nearby[$details[$k]['spoken']])) {
                $matched++;
            }
        }

        return ($matched / $length) >= 0.6;
    }

    /** An isolated extra word: said again, a fixed mistake, or genuinely added. */
    private function markSingleInsertions(array &$details): void
    {
        foreach ($details as $i => $entry) {
            if ($entry['miscue'] !== 'insertion') {
                continue;
            }

            $word = $entry['spoken'];

            if ($this->repeatsNeighbour($details, $i, $word) || $this->isFalseStart($details, $i, $word)) {
                $details[$i]['miscue'] = 'repetition';
                continue;
            }

            $target = $this->nextWordRead($details, $i);

            if ($target !== null
                && $target['status'] === 'correct'
                && $word !== $target['reference']
                && $this->soundsLike($word, $target['reference'])) {
                $details[$i]['miscue'] = 'self_correction';
                $details[$i]['corrected_to'] = $target['reference'];
            }
        }
    }

    private function repeatsNeighbour(array $details, int $index, string $word): bool
    {
        $from = max(0, $index - self::REPETITION_WINDOW);
        $to = min(count($details) - 1, $index + self::REPETITION_WINDOW);

        for ($k = $from; $k <= $to; $k++) {
            if ($k !== $index && $details[$k]['spoken'] === $word && $details[$k]['miscue'] !== 'off_passage') {
                return true;
            }
        }

        return false;
    }

    /** "ma" straight before "mabilis": the child started the word, stopped, and said it. */
    private function isFalseStart(array $details, int $index, string $word): bool
    {
        $next = $details[$index + 1]['spoken'] ?? null;

        return $next !== null
            && mb_strlen($word) < mb_strlen($next)
            && str_starts_with($next, $word);
    }

    /** The next word in the passage the child actually said something for. */
    private function nextWordRead(array $details, int $index): ?array
    {
        for ($k = $index + 1; $k < count($details); $k++) {
            if ($details[$k]['status'] === 'insertion') {
                continue;
            }

            return $details[$k];
        }

        return null;
    }

    /** A replaced word is a mispronunciation when it sounds like its target. */
    private function markReplacements(array &$details): void
    {
        foreach ($details as $i => $entry) {
            if ($entry['status'] !== 'substitution') {
                continue;
            }

            $details[$i]['similarity'] = $this->similarity($entry['reference'], $entry['spoken']);

            if ($this->soundsLike($entry['spoken'], $entry['reference'])) {
                $details[$i]['miscue'] = 'mispronunciation';
            }
        }
    }

    /** Silence before a word, measured from the end of the previous spoken word. */
    private function markPauses(array &$details): void
    {
        $previousEnd = null;

        foreach ($details as $i => $entry) {
            if ($entry['spoken'] === null || !isset($entry['start'], $entry['end'])) {
                continue;
            }

            if ($previousEnd !== null) {
                $gap = round($entry['start'] - $previousEnd, 2);

                if ($gap >= self::HESITATION_SECONDS) {
                    $details[$i]['pause_before'] = $gap;
                    $details[$i]['pause_kind'] = $gap >= self::LONG_PAUSE_SECONDS ? 'long_pause' : 'hesitation';
                }
            }

            $previousEnd = $entry['end'];
        }
    }

    private function count(array $details): array
    {
        $counts = array_fill_keys([
            'correct', 'mispronunciation', 'substitution', 'omission', 'not_reached', 'insertion',
            'repetition', 'self_correction', 'off_passage', 'hesitation', 'long_pause', 'unclear',
        ], 0);

        foreach ($details as $entry) {
            $counts[$entry['miscue']]++;

            if (isset($entry['pause_kind'])) {
                $counts[$entry['pause_kind']]++;
            }
            if (!empty($entry['unclear'])) {
                $counts['unclear']++;
            }
        }

        return $counts;
    }

    /** Off-passage words grouped back into the stretches they were spoken in. */
    private function offPassageSegments(array $details): array
    {
        $segments = [];
        $current = null;

        foreach ($details as $entry) {
            if ($entry['miscue'] !== 'off_passage') {
                if ($current) {
                    $segments[] = $current;
                    $current = null;
                }
                continue;
            }

            $current ??= ['text' => '', 'words' => 0, 'start' => $entry['start'] ?? null, 'end' => null];
            $current['text'] = trim($current['text'] . ' ' . $entry['spoken']);
            $current['words']++;
            $current['end'] = $entry['end'] ?? $current['end'];
        }

        if ($current) {
            $segments[] = $current;
        }

        return $segments;
    }

    private function soundsLike(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }

        if ($this->similarity($a, $b) >= self::SOUND_ALIKE_SIMILARITY) {
            return true;
        }

        // soundex/metaphone only understand plain letters.
        if (preg_match('/^[a-z]+$/', $a) && preg_match('/^[a-z]+$/', $b)) {
            return soundex($a) === soundex($b) || metaphone($a) === metaphone($b);
        }

        return false;
    }

    private function similarity(string $a, string $b): float
    {
        $longest = max(strlen($a), strlen($b));

        return $longest === 0 ? 1.0 : round(1 - (levenshtein($a, $b) / $longest), 2);
    }

    private function firstIndexWhere(array $items, callable $test): ?int
    {
        foreach ($items as $i => $item) {
            if ($test($item)) {
                return $i;
            }
        }

        return null;
    }

    private function lastIndexWhere(array $items, callable $test): ?int
    {
        for ($i = count($items) - 1; $i >= 0; $i--) {
            if ($test($items[$i])) {
                return $i;
            }
        }

        return null;
    }
}
