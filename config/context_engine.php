<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Push delivery via the bus
    |--------------------------------------------------------------------------
    |
    | The ActionBus dispatcher is the ONLY push delivery path since the v2
    | cutover (legacy notify() calls in Check* commands were deleted).
    | Setting this to false is a kill switch: ScoredActionPushDispatcher
    | logs to scored_action_push_dispatch:* instead of sending.
    */
    'push_via_bus' => env('CONTEXT_ENGINE_PUSH_VIA_BUS', true),
];
