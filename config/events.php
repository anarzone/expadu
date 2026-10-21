<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Quality threshold
    |--------------------------------------------------------------------------
    | The floor for what the feed shows. The ingest enrichment pass sets
    | quality_score on every event; curated events pass regardless. The AI
    | relevance score only orders events, never hides them.
    */

    'quality_threshold' => env('EVENTS_QUALITY_THRESHOLD', 0.5),

    /*
    |--------------------------------------------------------------------------
    | Optional model classification
    |--------------------------------------------------------------------------
    | Deterministic enrichment and venue resolution always run. Model-backed
    | translation, summaries, chips, and relevance stay dormant until enabled.
    */

    'classification' => [
        'enabled' => env('EVENTS_CLASSIFICATION_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Classifier confidence floor
    |--------------------------------------------------------------------------
    | Below this confidence the event is flagged needs_review and the
    | classifier's uncertain chips are dropped — a missing chip is
    | annoying, a wrong one destroys trust.
    */

    'review_confidence' => env('EVENTS_REVIEW_CONFIDENCE', 0.7),

    /*
    |--------------------------------------------------------------------------
    | Geocoding service area
    |--------------------------------------------------------------------------
    | Event-coordinate writes and audits require the complete official Cologne
    | Stadtteil polygon set. Missing or partial boundary data fails closed.
    */

    'geocoding' => [
        'expected_polygon_count' => 86,
    ],
];
