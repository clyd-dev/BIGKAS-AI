<?php

return [
    // The roster cap: the most children one batch may hold. A full roster must
    // clear the lane in a single rescore run (see RescoreScheduleTest).
    'batch_max_learners' => 15,
];
