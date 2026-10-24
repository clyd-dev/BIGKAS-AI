<?php

namespace App\Observers;

use App\Models\Assessment;
use App\Models\AssessmentBatch;
use App\Models\AssessmentVerdict;

/**
 * A verdict is what finishes a batch entry, and it is written to the verdicts
 * table, not to the assessment - so saving one fires no Assessment event and
 * AssessmentObserver would never hear about it. Without this, a batch whose
 * last verdict is recorded while nobody is looking at the board would sit at
 * `analyzing` for ever.
 *
 * It reads batch_id with one cheap value() rather than loading the assessment,
 * and does nothing further for a single-child verdict.
 */
class AssessmentVerdictObserver
{
    public function saved(AssessmentVerdict $verdict): void
    {
        $batchId = Assessment::whereKey($verdict->assessment_id)->value('batch_id');

        if ($batchId === null) {
            return;
        }

        AssessmentBatch::find($batchId)?->refreshStatus();
    }
}
