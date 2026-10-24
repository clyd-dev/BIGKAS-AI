<?php

return [
    // The roster cap: the most children one batch may hold. A full roster must
    // clear the lane in a single rescore run (see RescoreScheduleTest).
    'batch_max_learners' => 15,

    // The roster floor the setup form asks for. Two, because a "batch" of one is
    // just a single-child assessment and that already has its own screen.
    'batch_min_learners' => 2,

    // Batch assessment (one teacher assessing many children at once). Off by
    // default so it can be deployed dark; the batch routes answer 404 until on.
    //
    // Cast, not taken raw: an unset key, an empty `BATCH_ASSESSMENT_ENABLED=`,
    // and the strings "false"/"off"/"0" must all read as off, and phpunit.xml
    // hands over "false" as an empty string. Only a genuinely true value opens
    // the feature.
    'batch_enabled' => filter_var(env('BATCH_ASSESSMENT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
];
