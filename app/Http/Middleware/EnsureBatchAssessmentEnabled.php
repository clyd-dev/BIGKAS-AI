<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps batch assessment dark until BATCH_ASSESSMENT_ENABLED is on. It answers
 * 404, not 403: the feature should look absent, not forbidden.
 */
class EnsureBatchAssessmentEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('assessment.batch_enabled'), 404);

        return $next($request);
    }
}
