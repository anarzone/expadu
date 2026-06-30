<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Relevance threshold
    |--------------------------------------------------------------------------
    | Events scored below this by the ingest classifier are hidden
    | everywhere (UI and composer). Rows not yet scored (null) stay
    | visible — legacy data keeps working until reprocessed.
    */

    'relevance_threshold' => env('EVENTS_RELEVANCE_THRESHOLD', 0.5),

    /*
    |--------------------------------------------------------------------------
    | Quality threshold
    |--------------------------------------------------------------------------
    | Fallback bar for events the relevance classifier hasn't scored yet
    | (relevance is null). The ingest enrichment pass sets quality_score on
    | every event, so this keeps a real quality floor on the feed without
    | waiting for the relevance pipeline.
    */

    'quality_threshold' => env('EVENTS_QUALITY_THRESHOLD', 0.5),

    /*
    |--------------------------------------------------------------------------
    | Classifier confidence floor
    |--------------------------------------------------------------------------
    | Below this confidence the event is flagged needs_review and the
    | classifier's uncertain chips are dropped — a missing chip is
    | annoying, a wrong one destroys trust.
    */

    'review_confidence' => env('EVENTS_REVIEW_CONFIDENCE', 0.7),
];
