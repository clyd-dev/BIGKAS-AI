<?php

return [
    // The roster cap: the most children one batch may hold. A full roster must
    // clear the lane in a single rescore run (see RescoreScheduleTest).
    'batch_max_learners' => 15,

    // Batch assessment (one teacher assessing many children at once). Off by
    // default so it can be deployed dark; the batch routes answer 404 until on.
    'batch_enabled' => env('BATCH_ASSESSMENT_ENABLED', false),
];
