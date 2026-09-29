<?php

namespace App\Services;

/**
 * ReadingAnalyzerService
 *
 * Analyzes transcribed text against reference material to calculate
 * reading performance metrics and identify weaknesses.
 *
 * This is the core analysis component that:
 * 1. Compares transcription with reference text using dynamic programming alignment
 * 2. Calculates accuracy, fluency, and other metrics
 * 3. Classifies reading weaknesses (ML-first with rule-based fallback)
 */
class ReadingAnalyzerService
{
    protected ?MLClassificationService $mlService;

    public function __construct(?MLClassificationService $mlService = null)
    {
        // Use injected service if ML is enabled, otherwise null
        $this->mlService = config('services.ml_api.enabled', true) ? $mlService : null;
    }

    /**
     * Analyze reading performance
     *
     * @param array $transcription Transcription data from speech-to-text
     * @param string $referenceText Original passage text
     * @param float $durationSeconds Reading duration in seconds
     * @return array Complete analysis results
     */
    public function analyze(array $transcription, string $referenceText, float $durationSeconds): array
    {
        // Extract transcribed text
        $transcribedText = $transcription['text'] ?? '';
        $transcribedWords = $transcription['words'] ?? [];

        // Get reference words
        $referenceWords = $this->tokenizeText($referenceText);
        $spokenWords = $this->tokenizeText($transcribedText);

        // Calculate text comparison
        $comparison = $this->compareTexts($referenceWords, $spokenWords);

        // Calculate basic metrics
        $totalWords = count($referenceWords);
        $correctWords = $comparison['correct_count'];
        $errorCount = $comparison['error_count'];

        $accuracyRate = $totalWords > 0
            ? round(($correctWords / $totalWords) * 100, 1)
            : 0;

        // Calculate Words Per Minute (WPM)
        $wordsPerMinute = $durationSeconds > 0
            ? round(($correctWords / $durationSeconds) * 60, 1)
            : 0;

        // Calculate fluency score based on pauses and rhythm
        $fluencyScore = $this->calculateFluencyScore($transcribedWords, $durationSeconds);

        // Determine reading level based on accuracy
        $readingLevel = $this->determineReadingLevel($accuracyRate);

        // Analyze error patterns
        $errorAnalysis = $this->analyzeErrors($comparison['errors'], $referenceWords);

        // Classify primary weakness using ML or rules
        $weaknessClassification = $this->classifyWeakness(
            $accuracyRate,
            $wordsPerMinute,
            $fluencyScore,
            $errorAnalysis
        );

        // ── Compute the 3 new metrics from word-level timestamps ──
        $longPauseCount = $this->detectLongPauses($transcribedWords);
        $repetitionCount = $this->detectRepetitions($spokenWords);
        $prosodyScore = $this->estimateProsody($transcribedWords, $fluencyScore);

        // ── Build the 12-feature vector for ML classification ──
        // Column names MUST match the training script (parse_annotations.py)
        // and Flask /api/classify endpoint EXACTLY.
        $totalPatternErrors = max(
            ($errorAnalysis['patterns']['phonetic_confusion'] ?? 0) +
            ($errorAnalysis['patterns']['vowel_confusion'] ?? 0) +
            ($errorAnalysis['patterns']['consonant_blend_error'] ?? 0) +
            ($errorAnalysis['patterns']['other'] ?? 0),
            1 // Prevent division by zero
        );

        $mlFeatures = [
            'accuracy_rate'        => $accuracyRate,
            'words_per_minute'     => $wordsPerMinute,
            'fluency_score'        => $fluencyScore,
            'substitution_rate'    => $totalWords > 0 ? round($errorAnalysis['substitutions'] / $totalWords, 3) : 0,
            'omission_rate'        => $totalWords > 0 ? round($errorAnalysis['omissions'] / $totalWords, 3) : 0,
            'insertion_rate'       => $totalWords > 0 ? round($errorAnalysis['insertions'] / $totalWords, 3) : 0,
            'phonetic_error_rate'  => round(($errorAnalysis['patterns']['phonetic_confusion'] ?? 0) / $totalPatternErrors, 3),
            'vowel_error_rate'     => round(($errorAnalysis['patterns']['vowel_confusion'] ?? 0) / $totalPatternErrors, 3),
            'blend_error_rate'     => round(($errorAnalysis['patterns']['consonant_blend_error'] ?? 0) / $totalPatternErrors, 3),
            'self_correction_rate' => $totalWords > 0 ? round($repetitionCount / $totalWords, 3) : 0,
            'pause_frequency'      => $totalWords > 0 ? round($longPauseCount / $totalWords, 3) : 0,
            'prosody_score'        => $prosodyScore,
        ];

        return [
            // Basic metrics
            'accuracy_rate' => $accuracyRate,
            'words_per_minute' => $wordsPerMinute,
            'fluency_score' => $fluencyScore,
            'reading_level' => $readingLevel,

            // Error details
            'error_count' => $errorCount,
            'substitutions' => $errorAnalysis['substitutions'],
            'omissions' => $errorAnalysis['omissions'],
            'insertions' => $errorAnalysis['insertions'],
            'repetitions' => $repetitionCount,
            'self_corrections' => $errorAnalysis['self_corrections'],

            // Weakness classification (rule-based fallback)
            'primary_weakness' => $weaknessClassification['primary'],
            'secondary_weakness' => $weaknessClassification['secondary'],
            'confidence_score' => $weaknessClassification['confidence'],

            // ML-ready feature vector (12 features, sent to Flask /api/classify)
            'ml_features' => $mlFeatures,

            // Additional data
            'skill_scores' => $this->calculateSkillScores($accuracyRate, $fluencyScore, $errorAnalysis),
            'error_patterns' => $errorAnalysis['patterns'],
            'word_comparison' => $comparison['word_details'],

            // Metadata
            'total_words' => $totalWords,
            'correct_words' => $correctWords,
            'duration_seconds' => $durationSeconds,
            'long_pause_count' => $longPauseCount,
            'prosody_score' => $prosodyScore,
        ];
    }

    /**
     * Detect long pauses (>1 second gaps) from word-level timestamps.
     *
     * Uses the same threshold as the training script's [pause] tag (>1s),
     * which maps to pause_frequency in the 12-feature vector.
     * Whisper's word timestamps provide start/end times for each word,
     * so we measure the gap between consecutive words.
     */
    protected function detectLongPauses(array $words): int
    {
        if (count($words) < 2) {
            return 0;
        }

        $longPauses = 0;
        for ($i = 1; $i < count($words); $i++) {
            if (isset($words[$i]['start']) && isset($words[$i - 1]['end'])) {
                $gap = $words[$i]['start'] - $words[$i - 1]['end'];
                if ($gap > 1.0) {
                    $longPauses++;
                }
            }
        }

        return $longPauses;
    }

    /**
     * Detect word repetitions/stutters from the spoken word list.
     *
     * Counts consecutive identical or near-identical words as repetitions.
     * This maps to self_correction_rate in the 12-feature vector.
     *
     * Example: ["ang", "ang", "aso"] → 1 repetition
     * Example: ["ma", "mabilis"] → checked via prefix match (stutter pattern)
     *
     * NOTE: This is a documented approximation. The training script uses
     * hyphenated stutter patterns (e.g., "ma-mabilis") from annotated text,
     * while this live version detects consecutive duplicates from Whisper output.
     * Both approaches measure the same underlying behavior (child repeating words).
     */
    protected function detectRepetitions(array $spokenWords): int
    {
        if (count($spokenWords) < 2) {
            return 0;
        }

        $repetitions = 0;
        for ($i = 1; $i < count($spokenWords); $i++) {
            $prev = $spokenWords[$i - 1];
            $curr = $spokenWords[$i];

            // Exact repetition: "aso aso"
            if ($prev === $curr) {
                $repetitions++;
                continue;
            }

            // Stutter/false-start: short word is a prefix of the next word
            // e.g., "ma" followed by "mabilis", or "pa" followed by "paaralan"
            if (mb_strlen($prev) <= 3 && mb_strlen($curr) > 3 && str_starts_with($curr, $prev)) {
                $repetitions++;
            }
        }

        return $repetitions;
    }

    /**
     * Estimate prosody score (0-10 scale) from word-level data.
     *
     * Prosody = intonation, stress, rhythm, expression while reading.
     * No single acoustic feature captures this perfectly, so we use a
     * heuristic combining:
     * - Confidence variance (monotone reading = low variance in Whisper confidence)
     * - Fluency score (already accounts for pause rhythm)
     * - Speech rate consistency (standard deviation of inter-word timing)
     *
     * This is a DOCUMENTED APPROXIMATION. The training script's prosody_score
     * column is either NULL (filled by teacher) or computed similarly.
     * State this explicitly in your capstone methodology section.
     */
    protected function estimateProsody(array $words, float $fluencyScore): float
    {
        if (count($words) < 3) {
            return round($fluencyScore * 0.7, 1); // Minimal data, scale down fluency
        }

        $score = 10.0;

        // Factor 1: Confidence variance — monotone/uncertain reading shows
        // low or erratic confidence from Whisper
        $confidences = array_filter(array_column($words, 'confidence'), fn($c) => $c !== null);
        if (count($confidences) >= 3) {
            $mean = array_sum($confidences) / count($confidences);
            $variance = array_sum(array_map(fn($c) => pow($c - $mean, 2), $confidences)) / count($confidences);

            // Very low mean confidence = struggling reader
            if ($mean < 0.6) {
                $score -= 3;
            } elseif ($mean < 0.75) {
                $score -= 1.5;
            }

            // Extremely low variance = possibly monotone/robotic reading
            // (natural reading has SOME confidence variation)
            if ($variance < 0.005 && $mean > 0.8) {
                $score -= 1;
            }
        }

        // Factor 2: Inter-word timing consistency
        // Natural prosody has varied pacing; robotic reading is metronomic
        $gaps = [];
        for ($i = 1; $i < count($words); $i++) {
            if (isset($words[$i]['start']) && isset($words[$i - 1]['end'])) {
                $gaps[] = $words[$i]['start'] - $words[$i - 1]['end'];
            }
        }

        if (count($gaps) >= 3) {
            $meanGap = array_sum($gaps) / count($gaps);
            $gapVariance = array_sum(array_map(fn($g) => pow($g - $meanGap, 2), $gaps)) / count($gaps);
            $gapStdDev = sqrt($gapVariance);

            // Very high std dev = erratic pausing (struggling)
            if ($gapStdDev > 1.5) {
                $score -= 2;
            }
            // Very low std dev with fast pace = possibly rushing without expression
            if ($gapStdDev < 0.05 && $meanGap < 0.15) {
                $score -= 1;
            }
        }

        // Factor 3: Blend with fluency (prosody and fluency are correlated)
        $score = ($score * 0.6) + ($fluencyScore * 0.4);

        return max(0, min(10, round($score, 1)));
    }

    /**
     * Tokenize text into words
     *
     * Normalizes to lowercase, removes punctuation, splits on whitespace.
     * Supports Unicode characters (important for Filipino/Cebuano text).
     */
    protected function tokenizeText(string $text): array
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);

        return preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    }

    /**
     * Compare transcribed text with reference text
     *
     * Uses Levenshtein-based dynamic programming alignment to match
     * spoken words against reference words, identifying insertions,
     * omissions, substitutions, and correct matches.
     */
    protected function compareTexts(array $reference, array $spoken): array
    {
        $correctCount = 0;
        $errors = [];
        $wordDetails = [];

        // Use Levenshtein-based alignment
        $alignment = $this->alignTexts($reference, $spoken);

        foreach ($alignment as $pair) {
            $refWord = $pair['reference'];
            $spkWord = $pair['spoken'];

            if ($refWord === null) {
                // Insertion (extra word spoken)
                $errors[] = [
                    'type' => 'insertion',
                    'spoken' => $spkWord,
                    'position' => $pair['position'],
                ];
                $wordDetails[] = [
                    'reference' => null,
                    'spoken' => $spkWord,
                    'status' => 'insertion',
                ];
            } elseif ($spkWord === null) {
                // Omission (word skipped)
                $errors[] = [
                    'type' => 'omission',
                    'reference' => $refWord,
                    'position' => $pair['position'],
                ];
                $wordDetails[] = [
                    'reference' => $refWord,
                    'spoken' => null,
                    'status' => 'omission',
                ];
            } elseif ($refWord === $spkWord) {
                // Correct
                $correctCount++;
                $wordDetails[] = [
                    'reference' => $refWord,
                    'spoken' => $spkWord,
                    'status' => 'correct',
                ];
            } else {
                // Substitution (wrong word)
                $errors[] = [
                    'type' => 'substitution',
                    'reference' => $refWord,
                    'spoken' => $spkWord,
                    'similarity' => $this->calculateSimilarity($refWord, $spkWord),
                    'position' => $pair['position'],
                ];
                $wordDetails[] = [
                    'reference' => $refWord,
                    'spoken' => $spkWord,
                    'status' => 'substitution',
                ];
            }
        }

        return [
            'correct_count' => $correctCount,
            'error_count' => count($errors),
            'errors' => $errors,
            'word_details' => $wordDetails,
        ];
    }

    /**
     * Align reference and spoken texts using dynamic programming
     *
     * Implements a Levenshtein-distance-based alignment (edit distance)
     * with backtracking to produce an optimal word-level alignment.
     * Operations: match/substitute (diagonal), omission (up), insertion (left).
     */
    protected function alignTexts(array $reference, array $spoken): array
    {
        $m = count($reference);
        $n = count($spoken);

        // Build cost matrix
        $dp = [];
        $dp[0][0] = 0;

        for ($i = 1; $i <= $m; $i++) {
            $dp[$i][0] = $i;
        }
        for ($j = 1; $j <= $n; $j++) {
            $dp[0][$j] = $j;
        }

        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                $cost = $reference[$i - 1] === $spoken[$j - 1] ? 0 : 1;
                $dp[$i][$j] = min(
                    $dp[$i - 1][$j] + 1,         // Deletion (omission)
                    $dp[$i][$j - 1] + 1,         // Insertion
                    $dp[$i - 1][$j - 1] + $cost  // Match/Substitution
                );
            }
        }

        // Backtrack to get alignment
        $alignment = [];
        $i = $m;
        $j = $n;
        $position = max($m, $n);

        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $dp[$i][$j] === $dp[$i - 1][$j - 1] + ($reference[$i - 1] === $spoken[$j - 1] ? 0 : 1)) {
                array_unshift($alignment, [
                    'reference' => $reference[$i - 1],
                    'spoken' => $spoken[$j - 1],
                    'position' => $position--,
                ]);
                $i--;
                $j--;
            } elseif ($i > 0 && $dp[$i][$j] === $dp[$i - 1][$j] + 1) {
                array_unshift($alignment, [
                    'reference' => $reference[$i - 1],
                    'spoken' => null,
                    'position' => $position--,
                ]);
                $i--;
            } else {
                array_unshift($alignment, [
                    'reference' => null,
                    'spoken' => $spoken[$j - 1],
                    'position' => $position--,
                ]);
                $j--;
            }
        }

        return $alignment;
    }

    /**
     * Calculate string similarity between two words
     *
     * Returns a value between 0.0 (completely different) and 1.0 (identical).
     */
    protected function calculateSimilarity(string $a, string $b): float
    {
        $maxLen = max(strlen($a), strlen($b));
        if ($maxLen === 0) {
            return 1.0;
        }

        $distance = levenshtein($a, $b);

        return round(1 - ($distance / $maxLen), 2);
    }

    /**
     * Calculate fluency score (0-10 scale)
     *
     * Evaluates reading fluency based on:
     * - Pause duration between words (from word-level timestamps)
     * - Frequency of long pauses (> 1 second)
     * - Words per minute (normalized to ~100 WPM ideal for learners)
     */
    protected function calculateFluencyScore(array $words, float $duration): float
    {
        if (empty($words) || $duration <= 0) {
            return 5.0; // Default middle score
        }

        $score = 10.0;

        // Analyze pauses between words
        $pauses = [];
        for ($i = 1; $i < count($words); $i++) {
            if (isset($words[$i]['start']) && isset($words[$i - 1]['end'])) {
                $pause = $words[$i]['start'] - $words[$i - 1]['end'];
                $pauses[] = $pause;
            }
        }

        if (! empty($pauses)) {
            $avgPause = array_sum($pauses) / count($pauses);
            $longPauses = count(array_filter($pauses, fn ($p) => $p > 1.0));

            // Penalize for long average pause
            if ($avgPause > 0.5) {
                $score -= min(2, ($avgPause - 0.5) * 2);
            }

            // Penalize for many long pauses
            $longPauseRatio = $longPauses / count($pauses);
            $score -= $longPauseRatio * 3;
        }

        // Consider words per minute (normalized to 100 WPM as ideal for learners)
        $wpm = (count($words) / $duration) * 60;
        if ($wpm < 50) {
            $score -= (50 - $wpm) / 20;
        } elseif ($wpm > 150) {
            $score -= ($wpm - 150) / 50; // Too fast might indicate rushing
        }

        return max(0, min(10, round($score, 1)));
    }

    /**
     * Determine reading level based on accuracy percentage
     *
     * Uses standard reading level thresholds:
     * - Independent: >= 97% (can read on their own)
     * - Instructional: >= 90% (needs teacher guidance)
     * - Frustration: < 90% (material is too difficult)
     */
    protected function determineReadingLevel(float $accuracy): string
    {
        if ($accuracy >= 97) {
            return 'independent';
        } elseif ($accuracy >= 90) {
            return 'instructional';
        } else {
            return 'frustration';
        }
    }

    /**
     * Analyze error patterns from alignment errors
     *
     * Categorizes errors by type (substitution, omission, insertion, repetition)
     * and identifies common substitution patterns (phonetic confusion, vowel
     * confusion, consonant blend errors, etc.).
     */
    protected function analyzeErrors(array $errors, array $referenceWords): array
    {
        $substitutions = 0;
        $omissions = 0;
        $insertions = 0;
        $repetitions = 0;
        $selfCorrections = 0;
        $patterns = [];

        foreach ($errors as $error) {
            switch ($error['type']) {
                case 'substitution':
                    $substitutions++;

                    // Analyze substitution patterns
                    $pattern = $this->identifySubstitutionPattern(
                        $error['reference'] ?? '',
                        $error['spoken'] ?? ''
                    );
                    if ($pattern) {
                        $patterns[] = $pattern;
                    }
                    break;

                case 'omission':
                    $omissions++;
                    break;

                case 'insertion':
                    $insertions++;
                    break;

                case 'repetition':
                    $repetitions++;
                    break;
            }
        }

        // Identify common patterns
        $patternCounts = array_count_values($patterns);
        arsort($patternCounts);

        return [
            'substitutions' => $substitutions,
            'omissions' => $omissions,
            'insertions' => $insertions,
            'repetitions' => $repetitions,
            'self_corrections' => $selfCorrections,
            'patterns' => array_slice($patternCounts, 0, 5, true),
        ];
    }

    /**
     * Identify substitution pattern type
     *
     * Analyzes a substitution error to determine the likely cause:
     * - phonetic_confusion: Words sound similar (soundex match)
     * - initial_correct: Correct first 2 letters (guessed from beginning)
     * - ending_correct: Correct last 2 letters
     * - consonant_blend_error: Missed a consonant blend (bl, br, cl, etc.)
     * - vowel_confusion: Same consonant structure but wrong vowels
     */
    protected function identifySubstitutionPattern(string $reference, string $spoken): ?string
    {
        if (empty($reference) || empty($spoken)) {
            return null;
        }

        // Check for phonetically similar (soundex match)
        if (soundex($reference) === soundex($spoken)) {
            return 'phonetic_confusion';
        }

        // Check for similar beginning
        if (substr($reference, 0, 2) === substr($spoken, 0, 2)) {
            return 'initial_correct';
        }

        // Check for similar ending
        if (substr($reference, -2) === substr($spoken, -2)) {
            return 'ending_correct';
        }

        // Check for consonant blend issues
        $blends = [
            'bl', 'br', 'cl', 'cr', 'dr', 'fl', 'fr', 'gl', 'gr',
            'pl', 'pr', 'sc', 'sk', 'sl', 'sm', 'sn', 'sp', 'st', 'sw', 'tr', 'tw',
        ];
        foreach ($blends as $blend) {
            if (str_contains($reference, $blend) && ! str_contains($spoken, $blend)) {
                return 'consonant_blend_error';
            }
        }

        // Check for vowel confusion
        $refVowels = preg_replace('/[^aeiou]/i', '', $reference);
        $spkVowels = preg_replace('/[^aeiou]/i', '', $spoken);
        if ($refVowels !== $spkVowels && strlen($refVowels) === strlen($spkVowels)) {
            return 'vowel_confusion';
        }

        return 'other';
    }

    /**
     * Classify primary and secondary weaknesses (rule-based only).
     *
     * NOTE: This method intentionally uses ONLY rule-based classification.
     * The actual ML classification (Flask /api/classify) is called by the
     * AssessmentController::analyze() method, which then overrides the
     * rule-based result with the ML prediction. This avoids calling
     * Flask twice per assessment.
     *
     * The rule-based result here serves as a fallback in case:
     * - The Flask microservice is down
     * - The RF model is not loaded
     * - The controller's ML call fails for any reason
     */
    protected function classifyWeakness(
        float $accuracy,
        float $wpm,
        float $fluency,
        array $errorAnalysis
    ): array {
        return $this->ruleBasedClassification($accuracy, $wpm, $fluency, $errorAnalysis);
    }

    /**
     * Rule-based weakness classification
     *
     * Scores each weakness category based on error patterns and metrics:
     * 1 = Phonemic Awareness (phonetic/vowel/blend errors)
     * 2 = Decoding Accuracy (low accuracy, many substitutions)
     * 3 = Oral Reading Fluency (low WPM, poor fluency score)
     * 4 = Comprehension (many omissions, accurate but expressionless)
     */
    protected function ruleBasedClassification(
        float $accuracy,
        float $wpm,
        float $fluency,
        array $errorAnalysis
    ): array {
        $scores = [
            1 => 0, // Phonemic Awareness
            2 => 0, // Decoding Accuracy
            3 => 0, // Oral Reading Fluency
            4 => 0, // Comprehension
        ];

        // Phonemic Awareness indicators
        $phoneticErrors = $errorAnalysis['patterns']['phonetic_confusion'] ?? 0;
        $vowelErrors = $errorAnalysis['patterns']['vowel_confusion'] ?? 0;
        $blendErrors = $errorAnalysis['patterns']['consonant_blend_error'] ?? 0;
        $scores[1] = ($phoneticErrors + $vowelErrors + $blendErrors) * 10;

        // Decoding Accuracy indicators
        if ($accuracy < 90) {
            $scores[2] += (90 - $accuracy) * 2;
        }
        $scores[2] += ($errorAnalysis['substitutions'] * 3);

        // Fluency indicators
        if ($wpm < 80) {
            $scores[3] += (80 - $wpm) / 2;
        }
        if ($fluency < 6) {
            $scores[3] += (6 - $fluency) * 5;
        }

        // Comprehension indicators (inferred from other patterns)
        if ($errorAnalysis['omissions'] > 5) {
            $scores[4] += $errorAnalysis['omissions'] * 2;
        }
        if ($accuracy >= 90 && $fluency < 5) {
            // Can decode but lacks expression = potential comprehension issue
            $scores[4] += 15;
        }

        // Sort by score
        arsort($scores);
        $ranked = array_keys($scores);

        return [
            'primary' => $scores[$ranked[0]] > 10 ? $ranked[0] : null,
            'secondary' => $scores[$ranked[1]] > 5 ? $ranked[1] : null,
            'confidence' => $this->calculateConfidence($scores),
            'all_scores' => $scores,
        ];
    }

    /**
     * Calculate confidence score for classification
     *
     * Returns a value between 0.5 and 1.0 based on how clearly
     * the top-scoring weakness stands out from the others.
     */
    protected function calculateConfidence(array $scores): float
    {
        $sorted = array_values($scores);
        rsort($sorted);

        if ($sorted[0] === 0) {
            return 0.5; // No clear weakness
        }

        // Confidence based on how much the top score exceeds others
        $gap = $sorted[0] - $sorted[1];
        $confidence = min(1, 0.5 + ($gap / $sorted[0]) * 0.5);

        return round($confidence, 2);
    }

    /**
     * Calculate skill-specific scores (0-100 scale)
     *
     * Provides a breakdown of reading sub-skills:
     * - Phonemic: Inversely related to phonetic error count
     * - Decoding: Directly mapped from accuracy rate
     * - Fluency: Scaled from the 0-10 fluency score
     * - Comprehension: Estimated from accuracy and fluency combined
     */
    protected function calculateSkillScores(float $accuracy, float $fluency, array $errorAnalysis): array
    {
        // Phonemic awareness score (inverse of phonetic errors)
        $phoneticErrors = ($errorAnalysis['patterns']['phonetic_confusion'] ?? 0) +
                         ($errorAnalysis['patterns']['vowel_confusion'] ?? 0) +
                         ($errorAnalysis['patterns']['consonant_blend_error'] ?? 0);
        $phonemicScore = max(0, 100 - ($phoneticErrors * 10));

        // Decoding score based on accuracy
        $decodingScore = $accuracy;

        // Fluency score (scaled to 0-100)
        $fluencyScore = $fluency * 10;

        // Comprehension score (estimated from patterns)
        $comprehensionScore = ($accuracy * 0.3) + ($fluency * 7);

        return [
            'phonemic' => round($phonemicScore, 1),
            'decoding' => round($decodingScore, 1),
            'fluency' => round($fluencyScore, 1),
            'comprehension' => round($comprehensionScore, 1),
        ];
    }
}
