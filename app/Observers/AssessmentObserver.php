<?php

namespace App\Observers;

use App\Models\Assessment;

/**
 * Keeps a batch's stored status true to its entries, with nobody watching.
 *
 * This fires on every Assessment save in the whole application, including
 * every single-child one, so the null check on batch_id comes first and
 * nothing at all happens before it: no query, no relation load, no work. A
 * single-child save costs one null comparison, exactly as it did before
 * batches existed.
 */
class AssessmentObserver
{
    public function saved(Assessment $assessment): void
    {
        if ($assessment->batch_id === null) {
            return;
        }

        // refreshStatus() saves the batch, not the assessment, so this cannot
        // re-enter.
        $assessment->batch?->refreshStatus();
    }
}
