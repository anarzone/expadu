<?php

namespace App\ContextEngine;

use Carbon\CarbonInterface;

/**
 * Pure scoring math for ScoredActions.
 *
 *   score = severity_base × temporal_relevance × personal_relevance × freshness_decay
 */
class Scorer
{
    public const SEVERITY_BASE = [
        'critical' => 100.0,
        'major' => 70.0,
        'moderate' => 40.0,
        'minor' => 10.0,
        'info' => 5.0,
    ];

    public const TEMPORAL_INSIDE_WINDOW = 1.0;

    public const TEMPORAL_NEAR_WINDOW_MIN = 30;

    public const TEMPORAL_NEAR_WINDOW_VALUE = 0.7;

    public const TEMPORAL_WITHIN_2H_VALUE = 0.3;

    public const TEMPORAL_OUTSIDE_VALUE = 0.1;

    public const RELEVANCE_ROUTE_MATCH = 1.0;

    public const RELEVANCE_ROUTINE_MATCH = 0.8;

    public const RELEVANCE_GEO_PROXIMITY = 0.5;

    public const RELEVANCE_SITUATION_MATCH = 0.3;

    public const RELEVANCE_NONE = 0.1;

    public function score(
        string $severity,
        float $personalRelevance,
        float $temporalRelevance,
        ?CarbonInterface $eventOccurredAt = null,
    ): float {
        $base = self::SEVERITY_BASE[$severity] ?? self::SEVERITY_BASE['info'];

        $minutesSince = $eventOccurredAt
            ? max(0.0, abs(now()->diffInMinutes($eventOccurredAt, false)))
            : 0.0;
        $freshness = $minutesSince > 0 ? 0.95 ** $minutesSince : 1.0;

        return $base * $temporalRelevance * $personalRelevance * $freshness;
    }
}
