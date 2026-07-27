<?php

namespace App\Traits;

use App\Models\Learner;

trait AuthorizesLearnerAccess
{
    /**
     * Ensures the currently authenticated Teacher/Parent has access to this Learner.
     * Admins automatically bypass this check.
     */
    protected function authorizeLearnerAccess(Learner $learner): void
    {
        $user = auth()->user();

        // Admins can access all learners
        if ($user->isAdmin()) {
            return;
        }

        // Check if the user is attached to the learner via the pivot table
        $hasAccess = $user->learners()->where('learners.id', $learner->id)->exists();

        abort_unless($hasAccess, 403, 'Unauthorized. This student is not assigned to your class.');
    }
}
