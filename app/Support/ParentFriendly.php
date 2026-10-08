<?php

namespace App\Support;

/**
 * Turns technical reading-assessment output into plain, encouraging language
 * for parents. Presentation only: never changes stored data.
 */
class ParentFriendly
{
    /**
     * Reading level -> plain wording, colour class and short meaning.
     */
    public static function level(?string $level): array
    {
        return match ($level) {
            'independent' => [
                'key'     => 'independent',
                'emoji'   => '🌟',
                'title'   => 'Reads on their own',
                'short'   => 'Reading well',
                'tone'    => 'good',
                'meaning' => 'Your child can read this kind of text smoothly without help. Keep them reading every day, and try slightly harder books to keep them growing.',
            ],
            'instructional' => [
                'key'     => 'instructional',
                'emoji'   => '📘',
                'title'   => 'Reads with a little help',
                'short'   => 'Growing',
                'tone'    => 'ok',
                'meaning' => 'Your child reads well when an adult sits beside them and helps with the tricky words. This is a normal, healthy stage. Reading together at home helps a lot.',
            ],
            'frustration' => [
                'key'     => 'frustration',
                'emoji'   => '🤝',
                'title'   => 'Needs extra help',
                'short'   => 'Needs support',
                'tone'    => 'help',
                'meaning' => 'This text is still too hard for your child to read alone. That is okay. With short, friendly practice at home and the teacher\'s help, children improve step by step.',
            ],
            default => [
                'key'     => 'none',
                'emoji'   => '📖',
                'title'   => 'Not checked yet',
                'short'   => 'No result yet',
                'tone'    => 'none',
                'meaning' => 'Your child has not had a reading check yet. Results will show up here after the teacher records one.',
            ],
        };
    }

    /**
     * "Read 94 of every 100 words correctly."
     */
    public static function accuracySentence(?float $accuracy): string
    {
        if ($accuracy === null) {
            return 'No result yet.';
        }

        return 'Read ' . (int) round($accuracy) . ' out of every 100 words correctly.';
    }

    /**
     * Reading speed in words, compared with the usual range for the grade.
     * Returns ['text' => ..., 'tone' => good|ok|help|none].
     */
    public static function speed(?float $wpm, ?int $grade): array
    {
        if (!$wpm) {
            return ['text' => 'Speed not measured', 'tone' => 'none'];
        }

        $benchmarks = config('bigkas.wpm_benchmarks', []);
        $b = $benchmarks[$grade ?? 1] ?? ($benchmarks[1] ?? null);
        if (!$b) {
            return ['text' => 'Reads about ' . (int) round($wpm) . ' words a minute', 'tone' => 'none'];
        }

        return match (true) {
            $wpm >= $b['advanced'] => ['text' => 'Reads faster than most children this grade', 'tone' => 'good'],
            $wpm >= $b['target']   => ['text' => 'Reads at a good speed for this grade', 'tone' => 'good'],
            $wpm >= $b['min']      => ['text' => 'Getting close to the usual speed for this grade', 'tone' => 'ok'],
            default                => ['text' => 'Reads slower than usual for this grade. More practice will help', 'tone' => 'help'],
        };
    }

    /**
     * A 0-100 score -> 1 to 3 stars plus a word. No percentages shown to parents.
     */
    public static function skill(?float $score): array
    {
        if ($score === null) {
            return ['stars' => 0, 'word' => 'Not checked yet', 'tone' => 'none'];
        }

        return match (true) {
            $score >= 80 => ['stars' => 3, 'word' => 'Strong', 'tone' => 'good'],
            $score >= 60 => ['stars' => 2, 'word' => 'Getting there', 'tone' => 'ok'],
            default      => ['stars' => 1, 'word' => 'Needs practice', 'tone' => 'help'],
        };
    }

    /**
     * Plain labels for the four skills.
     */
    public static function skillLabels(): array
    {
        return [
            'phonemic'      => ['label' => 'Sounding out letters',  'icon' => 'bi-mic',           'hint' => 'Hearing the sounds in words'],
            'decoding'      => ['label' => 'Reading words right',   'icon' => 'bi-spellcheck',    'hint' => 'Saying each word correctly'],
            'fluency'       => ['label' => 'Reading smoothly',      'icon' => 'bi-wind',          'hint' => 'Not too slow, not stopping often'],
            'comprehension' => ['label' => 'Understanding the story', 'icon' => 'bi-lightbulb',   'hint' => 'Knowing what the text means'],
        ];
    }

    /**
     * One-line "how did it go compared with last time" for a result comparison.
     * Returns ['text' => ..., 'tone' => good|ok|help|none, 'icon' => bootstrap-icon].
     */
    public static function trend(?array $comparison): array
    {
        if (!$comparison) {
            return ['text' => 'This is the first reading check, so there is nothing to compare yet.', 'tone' => 'none', 'icon' => 'bi-flag'];
        }

        $acc = (float) ($comparison['accuracy_change'] ?? 0);

        if (!empty($comparison['level_changed'])) {
            return ['text' => 'The reading level changed since last time. Ask the teacher what this means.', 'tone' => 'ok', 'icon' => 'bi-arrow-repeat'];
        }

        return match (true) {
            $acc >= 3  => ['text' => 'Great news! Reading is better than last time.', 'tone' => 'good', 'icon' => 'bi-arrow-up-circle-fill'],
            $acc <= -3 => ['text' => 'A little lower than last time. This happens. Short daily practice helps.', 'tone' => 'help', 'icon' => 'bi-arrow-down-circle-fill'],
            default    => ['text' => 'About the same as last time. Steady practice keeps progress going.', 'tone' => 'ok', 'icon' => 'bi-dash-circle-fill'],
        };
    }

    /**
     * Plain explanation of the kinds of reading mistakes.
     */
    public static function mistakeTypes(): array
    {
        return [
            'substitutions'    => ['label' => 'Said a different word',   'icon' => 'bi-arrow-left-right', 'help' => 'The child read another word instead of the one on the page.'],
            'omissions'        => ['label' => 'Skipped a word',           'icon' => 'bi-skip-forward',     'help' => 'A word was missed while reading.'],
            'insertions'       => ['label' => 'Added an extra word',      'icon' => 'bi-plus-circle',      'help' => 'A word was added that is not in the text.'],
            'repetitions'      => ['label' => 'Repeated a word',          'icon' => 'bi-arrow-repeat',     'help' => 'The child said a word more than once.'],
            'self_corrections' => ['label' => 'Fixed it by themselves',   'icon' => 'bi-check-circle',     'help' => 'A good sign. The child noticed and corrected the mistake.'],
        ];
    }

    /**
     * Practical, low-cost tips parents can do at home, by weakness category id
     * (see config/bigkas.php weakness_categories).
     */
    public static function homeTips(?int $weakness): array
    {
        return match ($weakness) {
            1 => [
                'Say a word slowly together and clap for each sound, like "m - a - n - g - a".',
                'Play "What sound does it start with?" while cooking or walking.',
                'Sing rhyming songs and nursery rhymes together.',
            ],
            2 => [
                'Point to each word with a finger while your child reads.',
                'Pick 3 hard words a day and practice them on small cards.',
                'Let your child re-read the same short story until it feels easy.',
            ],
            3 => [
                'Read a page aloud first, then let your child read the same page.',
                'Read together at the same time, then slowly let your child take over.',
                'Praise smooth reading, not just correct words.',
            ],
            4 => [
                'After reading, ask "What happened first? What happened next?"',
                'Ask "Why do you think the character did that?"',
                'Let your child retell the story in their own words, in any language they like.',
            ],
            default => [
                'Read together for about 10 minutes every day.',
                'Let your child choose the book. Interest builds the habit.',
                'Praise effort: "I like how you tried that word again!"',
            ],
        };
    }

    /**
     * The first name of the child for sentences, falling back to "your child".
     */
    public static function name($learner): string
    {
        return $learner?->first_name ?: 'your child';
    }
}
