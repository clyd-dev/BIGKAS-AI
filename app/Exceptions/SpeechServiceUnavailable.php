<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * No speech engine could transcribe the recording.
 *
 * Thrown instead of returning the placeholder transcript, so an assessment is
 * never scored from invented text. The recording itself is already saved, so
 * the teacher can simply analyse again once the engine is back.
 */
class SpeechServiceUnavailable extends RuntimeException
{
    public static function becauseNoEngineAnswered(?string $lastError = null): self
    {
        return new self($lastError
            ? "No speech engine could transcribe the recording. Last error: {$lastError}"
            : 'No speech engine is available to transcribe the recording.');
    }

    /**
     * What a teacher should see: no internals, and the recovery first.
     *
     * The recording is already saved, so the next step is to analyse it again —
     * not to call the learner back and have them read the passage a second time.
     */
    public function forTeacher(): string
    {
        return 'The speech recognition service is not responding, so nothing was scored yet. '
            . 'Your recording is saved — tap Analyze to try again. '
            . 'If it keeps failing, ask your administrator to check the speech service; '
            . 'this assessment will be waiting under the learner\'s history, so there is no need to record again.';
    }
}
