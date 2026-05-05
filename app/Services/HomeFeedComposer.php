<?php

namespace App\Services;

use App\ContextEngine\ActionBus;
use App\ContextEngine\ScoredAction;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Replaces RecommendationService at the controller boundary.
 *
 * When CONTEXT_ENGINE_ENABLED=false: pure pass-through to RecommendationService.
 * When enabled: legacy feed first, then top ZSET actions are merged into
 * `recommendations`, deduped by action_key, capped per-type, sorted by score.
 *
 * The legacy class continues to build event/news/departure/settlement/commute_tip
 * cards during the migration window. Those types haven't been moved to evaluators
 * yet and are not affected by the cutover.
 */
class HomeFeedComposer
{
    /** Action types whose card output is owned by the new pipeline. */
    private const NEW_PIPELINE_TYPES = [
        'transit_disruption',
        'transit_delay',
        'alternative_route',
        'disruption_no_alt',
        'weather_alert',
        'buergeramt_slot',
        'rhine_level',
        'market_closure',
        'leave_by',
    ];

    /** Card types displaced from the legacy feed when the engine is enabled. */
    private const DISPLACED_LEGACY_CARD_TYPES = [
        'disruption',
        'accessibility_alert',
        'weather_alert',
    ];

    public function __construct(
        private RecommendationService $legacy,
        private ActionBus $bus,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildDashboardFeed(User $user, ?Request $request = null): array
    {
        $feed = $this->legacy->buildDashboardFeed($user, $request);

        if (! config('context_engine.enabled')) {
            return $feed;
        }

        $actions = $this->bus->topK($user->id, 20);
        if (empty($actions)) {
            return $feed;
        }

        $actionCards = array_filter(array_map(
            fn (ScoredAction $a) => $this->actionToCard($a),
            array_filter($actions, fn (ScoredAction $a) => in_array($a->type, self::NEW_PIPELINE_TYPES, true))
        ));

        if (empty($actionCards)) {
            return $feed;
        }

        $existingCards = array_filter(
            $feed['recommendations'] ?? [],
            fn (array $c) => ! in_array($c['type'] ?? '', self::DISPLACED_LEGACY_CARD_TYPES, true),
        );

        $merged = array_merge($actionCards, array_values($existingCards));

        usort($merged, fn (array $a, array $b) => ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0));

        $feed['recommendations'] = array_slice($merged, 0, 8);

        return $feed;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildCommuteRecommendation(User $user): array
    {
        return $this->legacy->getCommuteRecommendation($user);
    }

    /**
     * Translate a ScoredAction into the card shape that dashboard.tsx already consumes.
     *
     * @return array<string, mixed>|null
     */
    private function actionToCard(ScoredAction $action): ?array
    {
        $priority = (int) round($action->score);

        return match ($action->type) {
            'transit_disruption' => [
                'type' => 'disruption',
                'title' => $this->disruptionTitle($action),
                'subtitle' => 'On your route',
                'emoji' => '⚠️',
                'value' => '',
                'unit' => '',
                'priority' => $priority,
                'color' => match ($action->severity) {
                    'critical' => 'danger',
                    'major' => 'warn',
                    default => 'warn',
                },
                'meta' => [
                    'lines' => $action->payload['lines'] ?? [],
                    'severity' => $action->severity,
                    'type' => 'line',
                    'source' => 'context_engine',
                    'personal' => true,
                    'action_key' => $action->actionKey,
                ],
            ],
            'transit_delay' => [
                'type' => 'disruption',
                'title' => "Line {$action->payload['line']} delayed {$action->payload['delay_min']} min",
                'subtitle' => $action->payload['direction'] ?? '',
                'emoji' => '🕒',
                'value' => '',
                'unit' => '',
                'priority' => $priority,
                'color' => 'warn',
                'meta' => [
                    'lines' => [$action->payload['line'] ?? ''],
                    'severity' => $action->severity,
                    'type' => 'delay',
                    'source' => 'context_engine',
                    'personal' => true,
                    'action_key' => $action->actionKey,
                ],
            ],
            'alternative_route' => isset($action->payload['alternative']) ? [
                'type' => 'commute_tip',
                'title' => 'Alternative route',
                'subtitle' => $action->payload['alternative']['summary'] ?? '',
                'emoji' => '↪️',
                'value' => '+'.($action->payload['alternative']['extra_min'] ?? 0),
                'unit' => 'min',
                'priority' => $priority,
                'color' => 'info',
                'meta' => [
                    'route_id' => $action->payload['matched_route_id'] ?? null,
                    'action_key' => $action->actionKey,
                ],
            ] : null,
            'disruption_no_alt' => [
                'type' => 'commute_tip',
                'title' => 'No viable alternative',
                'subtitle' => 'Consider working from home or shifting your time.',
                'emoji' => '⚠️',
                'value' => '',
                'unit' => '',
                'priority' => $priority,
                'color' => 'warn',
                'meta' => ['action_key' => $action->actionKey],
            ],
            'weather_alert' => [
                'type' => 'weather_alert',
                'title' => $action->payload['alert']['title'] ?? 'Weather alert',
                'subtitle' => $action->payload['alert']['description'] ?? '',
                'emoji' => '🌧️',
                'value' => '',
                'unit' => '',
                'priority' => $priority,
                'color' => $action->severity === 'critical' ? 'danger' : 'warn',
                'meta' => ['action_key' => $action->actionKey],
            ],
            'buergeramt_slot' => [
                'type' => 'deadline_warning',
                'title' => 'Bürgeramt slot available',
                'subtitle' => count($action->payload['dates'] ?? []).' date(s) at '.($action->payload['office_id'] ?? ''),
                'emoji' => '📅',
                'value' => '',
                'unit' => '',
                'priority' => $priority,
                'color' => 'info',
                'meta' => ['action_key' => $action->actionKey],
            ],
            'rhine_level' => [
                'type' => 'weather_alert',
                'title' => 'Rhine level: '.($action->payload['level'] ?? '?').'m',
                'subtitle' => 'Threshold: '.($action->payload['threshold'] ?? ''),
                'emoji' => '🌊',
                'value' => '',
                'unit' => '',
                'priority' => $priority,
                'color' => 'warn',
                'meta' => ['action_key' => $action->actionKey],
            ],
            'market_closure' => [
                'type' => 'news',
                'title' => 'Market closed: '.($action->payload['day'] ?? ''),
                'subtitle' => $action->payload['reason'] ?? '',
                'emoji' => '🛒',
                'value' => '',
                'unit' => '',
                'priority' => $priority,
                'color' => 'neutral',
                'meta' => ['action_key' => $action->actionKey],
            ],
            'leave_by' => [
                'type' => 'commute_tip',
                'title' => 'Leave by reminder',
                'subtitle' => 'Time to head out.',
                'emoji' => '🚶',
                'value' => '',
                'unit' => '',
                'priority' => $priority,
                'color' => 'info',
                'meta' => ['action_key' => $action->actionKey],
            ],
            default => null,
        };
    }

    private function disruptionTitle(ScoredAction $action): string
    {
        $lines = $action->payload['lines'] ?? [];
        if (empty($lines)) {
            return 'Disruption on your route';
        }

        return 'Line '.implode(', ', array_slice($lines, 0, 3)).' disrupted';
    }
}
