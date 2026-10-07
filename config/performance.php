<?php

/*
|--------------------------------------------------------------------------
| Workflow Performance Points
|--------------------------------------------------------------------------
|
| Each successful workflow event awards its points to the exact user who
| performed it (see App\Services\Performance\PerformancePointService). Those
| points then feed the EXISTING Performance score: every awarded point adds
| `score_per_point` to that user's final score in
| PerformanceCalculationService::finalScore(), which is also what the
| scoreboard ranks and the monthly snapshots store.
|
| Change a value here and nothing else needs rewriting. Points already
| awarded keep the value they were awarded with, because the ledger stores
| each award's points.
|
*/

return [

    // Score points added to a user's final score for each awarded point.
    'score_per_point' => 0.25,

    // Points per successful event. The event keys are the single source of
    // truth; see PerformancePointEvent::EVENT_* for the constants.
    'points' => [
        'raw_content_approval' => 2,
        'advertising_content_approval' => 2,
        'poster_approval' => 2,
        'marketing_handover' => 1,
        'marketing_final_review' => 1,
        'smm_publish_success' => 2,
        'potential_client' => 3,
    ],

];
