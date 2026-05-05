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
];
