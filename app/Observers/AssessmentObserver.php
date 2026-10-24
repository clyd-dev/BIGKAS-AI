<?php

namespace App\Observers;

use App\Models\Assessment;
use App\Models\AssessmentBatch;

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

        // Found fresh, never through the cached $assessment->batch relation.
        // refreshStatus() decides whether to write by comparing against the
        // instance it holds, so a cached batch whose row has since been changed
        // by another instance - AssessmentVerdictObserver does exactly that -
        // would compare against its own last write, decline to write, and leave
        // the stored row saying `completed` with a stale completed_at while an
        // entry read `queued`. Both observers now recompute from a fresh
        // instance, so they cannot disagree.
        //
        // refreshStatus() saves the batch, not the assessment, so this cannot
        // re-enter.
        AssessmentBatch::find($assessment->batch_id)?->refreshStatus();
    }
}
