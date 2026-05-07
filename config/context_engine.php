<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Shadow mode
    |--------------------------------------------------------------------------
    |
    | When true, evaluators write ScoredActions to `pending_actions:{id}_shadow`
    | so the new pipeline can be observed without affecting the live dashboard
    | or push delivery. HomeFeedComposer continues serving from the legacy path.
    */
    'shadow' => env('CONTEXT_ENGINE_SHADOW', false),

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | When true, HomeFeedComposer reads from `pending_actions:{id}` (live)
    | and the controller serves it instead of legacy RecommendationService.
    */
    'enabled' => env('CONTEXT_ENGINE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Push delivery via the bus
    |--------------------------------------------------------------------------
    |
    | Roadmap #3. When false, ScoredActionPushDispatcher writes to the
    | scored_action_push_dispatch:* Redis log but does NOT actually fire
    | notify(). Lets us verify a 48h parity window against the legacy
    | notify() volume in the alerts table without double-pushing.
    |
    | When set to true, the dispatcher calls $user->notify() and the legacy
    | notify() calls in Check* commands MUST be removed in the same change.
    */
    'push_via_bus' => env('CONTEXT_ENGINE_PUSH_VIA_BUS', false),
];
