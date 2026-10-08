<?php

namespace App\Services;

use App\Models\Assessment;

/**
 * Explains how an AI result was produced and what the teacher should watch out
 * for before trusting it.
 *
 * This is the decision-support half of BIGKAS: the Random Forest and Whisper
 * give a prediction, and this service surfaces the conditions that prediction
 * was made under — which engines actually ran, how confident they were, and
 * whether anything about the recording suggests a re-assessment instead.
 */
class AnalysisAdvisorService
{
    // Below these, the pipeline is telling us not to trust the numbers.
    private const LOW_WORD_CONFIDENCE = 0.70;
    private const LOW_MODEL_CONFIDENCE = 0.60;
    private const IMPLAUSIBLE_ACCURACY = 40.0;
    private const MIN_CAPTURED_RATIO = 0.60;
    private const MIN_DURATION_SECONDS = 10;
    private const MAX_PLAUSIBLE_WPM = 250;

    public function for(Assessment $assessment): array
    {
        $result = $assessment->result;

        if (!$result) {
            return ['pipeline' => [], 'metrics' => [], 'findings' => [], 'model' => []];
        }

        $ml = $result->ml_analysis_json ?? [];
        $provenance = $ml['provenance'] ?? [];

        return [
            'pipeline' => $this->pipeline($assessment, $provenance),
            'metrics' => $this->metrics($assessment, $result, $ml, $provenance),
            'findings' => $this->findings($assessment, $result, $ml, $provenance),
            'model' => $this->modelInfo($provenance),
            'audio' => $this->audioSummary($ml),
        ];
    }

    /**
     * The recording described for a teacher rather than an audio engineer:
     * one sentence on how clear it was, a few plain facts, and a list of
     * anything heard that wasn't the child reading. The decibel figures are
     * kept, but under "technical details".
     */
    public function audioSummary(array $ml): array
    {
        $quality = $ml['audio_quality'] ?? null;
        $nonReading = $ml['non_reading'] ?? [];

        if (!is_array($quality) || !($quality['measured'] ?? false)) {
            return [
                'measured' => false,
                'tone' => 'neutral',
                'headline' => 'Recording quality was not checked',
                'detail' => 'Noise and background sounds are only checked when the school\'s own speech service analyses the recording.',
                'items' => $this->audioItems($nonReading, []),
                'technical' => [],
            ];
        }

        $headline = match ($quality['rating'] ?? null) {
            'clean' => ['Clear recording', 'The child\'s voice is easy to hear, with little background noise.', 'good'],
            'moderate_noise' => ['Some background noise', 'The child\'s voice can be heard, but a few words may have been misheard.', 'warn'],
            'noisy' => ['Noisy recording', 'The background is loud compared with the child\'s voice, so words may have been misheard. Play the recording to check.', 'bad'],
            'no_speech' => ['No voice heard', 'Nobody can be heard in this recording.', 'bad'],
            default => ['Recording could not be rated', 'There was not enough sound to tell how clear it is.', 'neutral'],
        };

        $total = max(0.01, (float) ($quality['total_seconds'] ?? 0));
        $speech = (float) ($quality['speech_seconds'] ?? 0);
        $snr = $quality['snr_db'] ?? null;

        $technical = array_filter([
            'Voice above the background' => $snr !== null ? $snr . ' dB' : null,
            'Background level' => isset($quality['noise_floor_db']) ? $quality['noise_floor_db'] . ' dB' : null,
            'Distortion (clipping)' => round(($quality['clipping_ratio'] ?? 0) * 100, 2) . '%',
            'Silence before reading' => isset($quality['leading_silence']) ? $quality['leading_silence'] . ' s' : null,
            'Silence after reading' => isset($quality['trailing_silence']) ? $quality['trailing_silence'] . ' s' : null,
        ], fn ($v) => $v !== null);

        return [
            'measured' => true,
            'tone' => $headline[2],
            'headline' => $headline[0],
            'detail' => $headline[1],
            'total_seconds' => (int) round($total),
            'speech_seconds' => (int) round($speech),
            'speech_pct' => (int) round(min(100, $speech / $total * 100)),
            'noise' => $snr === null ? null : ($snr >= 20 ? 'Low' : ($snr >= 10 ? 'Medium' : 'High')),
            'distorted' => ($quality['clipping_ratio'] ?? 0) > 0.01,
            'items' => $this->audioItems($nonReading, $quality['background_events'] ?? []),
            'technical' => $technical,
        ];
    }

    /** Everything heard that wasn't the child reading, in time order. */
    private function audioItems(array $nonReading, array $events): array
    {
        $items = [];

        foreach ($nonReading['off_passage'] ?? [] as $segment) {
            $items[] = [
                'kind' => 'talk',
                'title' => 'Something was said that isn\'t in the passage',
                'quote' => $segment['text'],
                'detail' => 'Talking, a title, or another voice. It is not counted as a reading mistake.',
                'at' => $segment['start'] ?? null,
            ];
        }

        foreach ($events as $event) {
            $items[] = [
                'kind' => 'noise',
                'title' => 'A noise between words',
                'quote' => null,
                'detail' => 'Something other than the child\'s voice, about ' . max(1, round($event['end'] - $event['start'])) . ' second' . (round($event['end'] - $event['start']) > 1 ? 's' : '') . ' long. Not counted as a mistake.',
                'at' => $event['start'],
            ];
        }

        foreach ($nonReading['sounds'] ?? [] as $sound) {
            $items[] = [
                'kind' => 'sound',
                'title' => ucfirst($sound['label']),
                'quote' => null,
                'detail' => 'A sound the computer noticed. Not counted as a mistake.',
                'at' => $sound['start'] ?? null,
            ];
        }

        foreach ($nonReading['unreliable_segments'] ?? [] as $segment) {
            $items[] = [
                'kind' => 'doubtful',
                'title' => 'Words that may not have been spoken',
                'quote' => $segment['text'],
                'detail' => 'The computer wasn\'t sure anyone was speaking here, so these words may have been made up.',
                'at' => $segment['start'] ?? null,
            ];
        }

        usort($items, fn ($a, $b) => ($a['at'] ?? PHP_INT_MAX) <=> ($b['at'] ?? PHP_INT_MAX));

        return $items;
    }

    /** What produced each stage of this result. */
    private function pipeline(Assessment $assessment, array $provenance): array
    {
        $engine = $provenance['stt_engine'] ?? null;

        $stt = match ($engine) {
            'local_whisper' => ['label' => 'faster-whisper (local)', 'state' => 'ok'],
            'openai_api' => ['label' => 'OpenAI Whisper API', 'state' => 'ok'],
            'mock' => ['label' => 'Mock data — not the child\'s audio', 'state' => 'critical'],
            default => ['label' => 'Not recorded', 'state' => 'unknown'],
        };

        $classifier = match ($provenance['classifier'] ?? null) {
            'ml' => ['label' => 'Random Forest' . (isset($provenance['model_version']) ? ' v' . $provenance['model_version'] : ''), 'state' => 'ok'],
            'rule_based' => ['label' => 'Rule-based fallback — ML service offline', 'state' => 'warning'],
            default => ['label' => 'Not recorded', 'state' => 'unknown'],
        };

        if ($assessment->comprehensionAnswers->isEmpty()) {
            $comprehension = ['label' => 'Not administered', 'state' => 'unknown'];
        } else {
            $comprehension = ['label' => 'Administered · ' . $assessment->comprehensionScore() . '%', 'state' => 'ok'];
        }

        return [
            'Speech-to-Text' => $stt,
            'Weakness Classifier' => $classifier,
            'Comprehension' => $comprehension,
        ];
    }

    private function metrics(Assessment $assessment, $result, array $ml, array $provenance): array
    {
        $wordConfidence = $provenance['mean_word_confidence'] ?? $this->meanWordConfidence($assessment);

        return [
            'Model confidence' => $result->confidence_score !== null
                ? round($result->confidence_score * 100) . '%' : '—',
            'Speech recognition confidence' => $wordConfidence !== null
                ? round($wordConfidence * 100) . '%' : '—',
            'Accuracy' => number_format((float) $result->accuracy_rate, 1) . '%',
            'Words per minute' => number_format((float) $result->words_per_minute, 0),
            'Fluency score' => $result->fluency_score !== null ? number_format((float) $result->fluency_score, 1) . ' / 10' : '—',
            'Prosody score' => $result->prosody_score !== null ? number_format((float) $result->prosody_score, 1) . ' / 10' : '—',
            'Words in passage' => (string) ($ml['total_words'] ?? '—'),
            'Words captured' => isset($ml['total_words']) ? (string) $this->capturedWords($ml) : '—',
            'Long pauses' => (string) ($ml['long_pause_count'] ?? 0),
            'Recording length' => gmdate('i:s', (int) ($ml['duration_seconds'] ?? 0)),
        ];
    }

    /**
     * Checks worth showing the teacher, most serious first. Each is a plain
     * statement of what happened plus the action it suggests.
     */
    private function findings(Assessment $assessment, $result, array $ml, array $provenance): array
    {
        $findings = [];

        if (($provenance['stt_engine'] ?? null) === 'mock' || ($provenance['stt_is_mock'] ?? false)) {
            $findings[] = [
                'level' => 'critical',
                'title' => 'This result is not based on the child\'s audio',
                'message' => 'No speech-to-text engine was reachable, so placeholder text was analysed instead. Every number on this page is meaningless.',
                'action' => 'Start the transcription service and re-assess this learner.',
            ];
        }

        foreach ($this->audioFindings($ml) as $finding) {
            $findings[] = $finding;
        }

        if (($provenance['classifier'] ?? null) === 'rule_based') {
            $findings[] = [
                'level' => 'warning',
                'title' => 'Weakness came from a rule, not the model',
                'message' => 'The ML service was unreachable, so a fixed rule estimated the weakness at low confidence.',
                'action' => 'Bring the ML service up and re-run the analysis for a model-based classification.',
            ];
        }

        $wordConfidence = $provenance['mean_word_confidence'] ?? $this->meanWordConfidence($assessment);

        if ($wordConfidence !== null && $wordConfidence < self::LOW_WORD_CONFIDENCE) {
            $findings[] = [
                'level' => 'warning',
                'title' => 'Speech recognition was unsure of the words',
                'message' => 'Average word confidence was ' . round($wordConfidence * 100) . '%, which usually means background noise or an unclear recording.',
                'action' => 'Listen to the recording. If it is hard to follow, re-assess somewhere quieter.',
            ];
        }

        if ((float) $result->accuracy_rate < self::IMPLAUSIBLE_ACCURACY) {
            $findings[] = [
                'level' => 'warning',
                'title' => 'Accuracy is unusually low',
                'message' => 'An accuracy of ' . number_format((float) $result->accuracy_rate, 1) . '% more often means the recording was unclear than that the child misread almost every word.',
                'action' => 'Compare the recording against the passage before accepting this result.',
            ];
        }

        $totalWords = (int) ($ml['total_words'] ?? 0);
        $captured = $this->capturedWords($ml);

        if ($totalWords > 0 && $captured > 0 && ($captured / $totalWords) < self::MIN_CAPTURED_RATIO) {
            $findings[] = [
                'level' => 'warning',
                'title' => 'Only part of the passage was captured',
                'message' => $captured . ' of ' . $totalWords . ' words were picked up, so the recording may have been cut short or the child stopped early.',
                'action' => 'Check whether the child finished reading; re-assess if the recording ended too soon.',
            ];
        }

        $duration = (float) ($ml['duration_seconds'] ?? 0);

        if ($duration > 0 && $duration < self::MIN_DURATION_SECONDS) {
            $findings[] = [
                'level' => 'warning',
                'title' => 'Recording is very short',
                'message' => 'The recording is only ' . round($duration) . ' seconds long, which is rarely enough for a reliable reading measure.',
                'action' => 'Re-assess and make sure recording runs for the whole passage.',
            ];
        }

        if ((float) $result->words_per_minute > self::MAX_PLAUSIBLE_WPM) {
            $findings[] = [
                'level' => 'warning',
                'title' => 'Reading speed looks implausible',
                'message' => number_format((float) $result->words_per_minute, 0) . ' words per minute is beyond a typical elementary reader, which usually points to a timing problem in the recording.',
                'action' => 'Verify the recording length before using this result.',
            ];
        }

        if ($result->confidence_score !== null && (float) $result->confidence_score < self::LOW_MODEL_CONFIDENCE) {
            $findings[] = [
                'level' => 'info',
                'title' => 'The model is not confident in this classification',
                'message' => 'Confidence was ' . round((float) $result->confidence_score * 100) . '%, so the suggested weakness is weak evidence on its own.',
                'action' => 'Weigh your own observation of the child more heavily than the suggested weakness.',
            ];
        }

        // Most serious first, keeping the order above within each level.
        $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];
        $findings = collect($findings)->sortBy(fn ($f) => $rank[$f['level']] ?? 3)->values()->all();

        if (empty($findings)) {
            $findings[] = [
                'level' => 'ok',
                'title' => 'No problems detected in the analysis',
                'message' => 'The recording, transcription and classification all look within normal ranges.',
                'action' => 'Review the result and record your decision below.',
            ];
        }

        return $findings;
    }

    /**
     * What the recording itself says: was there speech, how noisy was it, and
     * was anything captured that wasn't the child reading.
     */
    private function audioFindings(array $ml): array
    {
        $quality = $ml['audio_quality'] ?? null;
        $nonReading = $ml['non_reading'] ?? [];
        $findings = [];

        if (is_array($quality) && ($quality['measured'] ?? false)) {
            $rating = $quality['rating'] ?? null;

            if ($rating === 'no_speech') {
                $findings[] = [
                    'level' => 'critical',
                    'title' => 'No speech was detected in this recording',
                    'message' => 'The microphone captured ' . round((float) $quality['total_seconds']) . ' seconds with no voice in it. Any words in the transcript were invented by the speech engine, so the scores describe nothing.',
                    'action' => 'Check the microphone and re-assess. Mark this result invalid.',
                ];
            } elseif ($rating === 'noisy') {
                $findings[] = [
                    'level' => 'warning',
                    'title' => 'The recording is noisy',
                    'message' => 'The child\'s voice was only ' . $quality['snr_db'] . ' dB above the background. Below 10 dB, words are regularly misheard, which shows up as miscues the child did not make.',
                    'action' => 'Listen to the recording; re-assess somewhere quieter or closer to the microphone.',
                ];
            } elseif ($rating === 'moderate_noise') {
                $findings[] = [
                    'level' => 'info',
                    'title' => 'Some background noise',
                    'message' => 'The child\'s voice was ' . $quality['snr_db'] . ' dB above the background — usable, but a few words may have been misheard.',
                    'action' => 'Spot-check the flagged words against the recording.',
                ];
            }

            if (($quality['clipping_ratio'] ?? 0) > 0.01) {
                $findings[] = [
                    'level' => 'warning',
                    'title' => 'The recording is distorted',
                    'message' => 'The audio is clipping, which happens when the voice is too loud or too close to the microphone and makes words harder to recognise.',
                    'action' => 'Move the microphone back a little and re-assess if words look wrong.',
                ];
            }

            $events = $quality['background_events'] ?? [];
            if (!empty($events)) {
                $findings[] = [
                    'level' => 'info',
                    'title' => count($events) . ' background sound' . (count($events) === 1 ? '' : 's') . ' between words',
                    'message' => 'Something other than the child\'s voice was picked up at ' . $this->timeList($events) . '.',
                    'action' => 'Play those moments to see whether the child was interrupted or distracted.',
                ];
            }
        }

        $offPassage = $nonReading['off_passage'] ?? [];
        if (!empty($offPassage)) {
            $words = array_sum(array_column($offPassage, 'words'));
            $findings[] = [
                'level' => 'info',
                'title' => $words . ' spoken word' . ($words === 1 ? '' : 's') . ' were not part of the passage',
                'message' => 'Heard: "' . implode('" / "', array_slice(array_column($offPassage, 'text'), 0, 3)) . '". This is treated as talk, a title or another voice — not counted as reading errors.',
                'action' => 'If the child was in fact misreading the passage here, override the result.',
            ];
        }

        if (!empty($nonReading['unreliable_segments'])) {
            $findings[] = [
                'level' => 'warning',
                'title' => 'Part of the transcript may be invented',
                'message' => 'The speech engine itself doubted that ' . count($nonReading['unreliable_segments']) . ' stretch(es) contained speech, yet wrote words for them: "' . implode('" / "', array_slice(array_column($nonReading['unreliable_segments'], 'text'), 0, 2)) . '".',
                'action' => 'Listen at those points; if nobody was speaking, the miscues there are not real.',
            ];
        }

        if (!empty($nonReading['sounds'])) {
            $findings[] = [
                'level' => 'info',
                'title' => 'Non-speech sounds were captured',
                'message' => 'The speech engine noted: ' . implode(', ', array_unique(array_column($nonReading['sounds'], 'label'))) . '.',
                'action' => 'These are shown as sounds and not scored as words.',
            ];
        }

        return $findings;
    }

    private function timeList(array $events): string
    {
        $times = array_map(fn ($e) => gmdate('i:s', (int) ($e['start'] ?? 0)), array_slice($events, 0, 4));

        return implode(', ', $times) . (count($events) > 4 ? ' and more' : '');
    }

    private function modelInfo(array $provenance): array
    {
        $metadata = $this->modelMetadata();

        return [
            'version' => $provenance['model_version'] ?? ($metadata['version'] ?? null),
            'type' => $metadata['model_type'] ?? 'Random Forest',
            'test_accuracy' => isset($metadata['test_accuracy']) ? round($metadata['test_accuracy'] * 100, 1) : null,
            'cv_accuracy' => isset($metadata['cv_accuracy']) ? round($metadata['cv_accuracy'] * 100, 1) : null,
            'samples' => $metadata['total_samples'] ?? null,
        ];
    }

    private function modelMetadata(): array
    {
        $path = base_path('ml-service/model_metadata.json');

        if (!is_file($path)) {
            return [];
        }

        return json_decode((string) file_get_contents($path), true) ?: [];
    }

    private function capturedWords(array $ml): int
    {
        $total = (int) ($ml['total_words'] ?? 0);
        $omissions = (int) ($ml['omissions'] ?? 0);

        return max(0, $total - $omissions);
    }

    private function meanWordConfidence(Assessment $assessment): ?float
    {
        $words = $assessment->transcription['words'] ?? [];
        $values = array_filter(array_column($words, 'confidence'), fn ($v) => $v !== null);

        if (empty($values)) {
            return null;
        }

        return array_sum($values) / count($values);
    }
}
