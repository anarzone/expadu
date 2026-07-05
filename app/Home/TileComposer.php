<?php

namespace App\Home;

use App\ContextEngine\ActionBus;
use App\ContextEngine\ScoredAction;
use App\Models\UserEvent;
use App\Services\GermanHolidayService;

/**
 * Composes the Today screen's urgency-ranked tiles from two sources:
 *
 * 1. The ActionBus — event-driven actions scored by the ContextEngine
 *    (disruptions, delays, weather alerts, Bürgeramt slots, Rhine, markets).
 * 2. Synthetic always-on tiles computed at request time — bureaucracy
 *    deadlines, German-rhythm warnings (holiday/Sunday closures),
 *    and tonight's events.
 *
 * A deadline three days away outranks a popular event: ranking is purely
 * by score, never by category.
 */
class TileComposer
{
    /** Fixed baselines for synthetic tiles, comparable with bus scores. */
    private const SCORE_OVERDUE = 95.0;

    private const SCORE_CRITICAL_DEADLINE = 85.0;

    private const SCORE_URGENT_DEADLINE = 65.0;

    private const SCORE_RHYTHM_WARNING = 70.0;

    private const SCORE_TONIGHT_EVENTS = 40.0;

    /** Tonight's events only earn an urgency tile if the soonest is this close. */
    private const TONIGHT_URGENT_MINUTES = 120;

    private const MAX_TILES = 8;

    /** A dismissal demotes its tile TYPE in ranking for this many days. */
    private const DEMOTE_WINDOW_DAYS = 7;

    /** Score penalty per dismissal of a type, and the most it can ever subtract. */
    private const DEMOTE_STEP = 20.0;

    private const DEMOTE_CAP = 60.0;

    /**
     * Tile types whose consequence is severe enough that a user's own
     * dismissals must never bury them — suppressing a legal deadline is the app
     * helping the user fail. Immunity keys on CONSEQUENCE, not the severity
     * colour: an "urgent" deadline renders as info/warn (not danger) yet is
     * still catastrophic to miss, so severity alone under-protects it.
     */
    private const DEMOTE_IMMUNE_TYPES = ['bureaucracy_deadline'];

    public function __construct(
        private ActionBus $bus,
        private GermanHolidayService $holidays,
        private TileTriage $triage,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function tiles(HomeContext $context): array
    {
        $tiles = [
            ...$this->busTiles($context),
            ...$this->deadlineTiles($context),
            ...$this->rhythmTiles(),
            ...$this->tonightEventsTile($context),
        ];

        // Drop tiles the user has triaged (done/snooze/dismiss) until their TTL
        // lapses — so a cleared alert stays gone across reloads, not just for the
        // current render.
        $tiles = array_values(array_filter(
            $tiles,
            fn (Tile $tile) => $tile->key === ''
                || ! $this->triage->isActive($context->userId, $tile->type, $tile->key),
        ));

        // Rank by score, demoting types the user has recently dismissed so
        // "you'll see fewer like this" is real — but never a danger-severity
        // tile nor a consequence-floor type (a storm, or any legal deadline,
        // must not be buried by a past dismiss). See rank().
        $penalties = $this->dismissPenalties($context->userId);
        usort($tiles, fn (Tile $a, Tile $b) => $this->rank($b, $penalties) <=> $this->rank($a, $penalties));

        return array_map(
            fn (Tile $tile) => $tile->toArray(),
            array_slice($tiles, 0, self::MAX_TILES),
        );
    }

    /** Effective sort score: the tile's score less any dismissal demotion. */
    private function rank(Tile $tile, array $penalties): float
    {
        if ($tile->severity === 'danger' || in_array($tile->type, self::DEMOTE_IMMUNE_TYPES, true)) {
            return $tile->score;
        }

        return $tile->score - ($penalties[$tile->type] ?? 0.0);
    }

    /**
     * Recent dismissals → a per-type score penalty. Repeatedly dismissing a kind
     * of alert sinks it down the feed (and off the cap) for a week, then it fades.
     *
     * @return array<string, float>
     */
    private function dismissPenalties(int $userId): array
    {
        $counts = [];
        $payloads = UserEvent::query()
            ->where('user_id', $userId)
            ->where('event_type', 'card_dismissed')
            ->where('created_at', '>=', now()->subDays(self::DEMOTE_WINDOW_DAYS))
            ->pluck('payload');

        foreach ($payloads as $payload) {
            $type = (string) (($payload['card_type'] ?? '') ?: '');
            if ($type !== '') {
                $counts[$type] = ($counts[$type] ?? 0) + 1;
            }
        }

        return array_map(fn (int $n) => min($n * self::DEMOTE_STEP, self::DEMOTE_CAP), $counts);
    }

    /**
     * @return list<Tile>
     */
    private function busTiles(HomeContext $context): array
    {
        $tiles = [];

        foreach ($this->bus->topK($context->userId, 20) as $action) {
            if (! in_array(ScoredAction::CHANNEL_DASHBOARD, $action->deliverChannels, true)) {
                continue;
            }

            $tile = $this->actionToTile($action);
            if ($tile !== null) {
                $tiles[] = $tile;
            }
        }

        return $tiles;
    }

    private function actionToTile(ScoredAction $action): ?Tile
    {
        return match ($action->type) {
            'transit_disruption' => new Tile(
                type: 'transit_disruption',
                title: $this->disruptionTitle($action),
                subtitle: $action->severity === 'critical'
                    ? 'Major impact — check before you leave'
                    : 'Affects stops near your places',
                emoji: '⚠️',
                severity: $action->severity === 'critical' ? 'danger' : 'warn',
                score: $action->score,
                key: $action->actionKey,
                href: '/alerts',
                meta: [
                    'lines' => $action->payload['lines'] ?? [],
                    'action_key' => $action->actionKey,
                ],
            ),
            'transit_delay' => new Tile(
                type: 'transit_delay',
                title: "Line {$action->payload['line']} delayed {$action->payload['delay_min']} min",
                subtitle: (string) ($action->payload['direction'] ?? ''),
                emoji: '🕒',
                severity: 'warn',
                score: $action->score,
                key: $action->actionKey,
                href: '/alerts',
                meta: [
                    'line' => $action->payload['line'] ?? '',
                    'action_key' => $action->actionKey,
                ],
            ),
            'weather_alert' => new Tile(
                type: 'weather_alert',
                title: (string) ($action->payload['alert']['title'] ?? 'Weather alert'),
                subtitle: (string) ($action->payload['alert']['description'] ?? ''),
                emoji: '🌧️',
                severity: $action->severity === 'critical' ? 'danger' : 'warn',
                score: $action->score,
                key: $action->actionKey,
                meta: ['action_key' => $action->actionKey],
            ),
            'buergeramt_slot' => new Tile(
                type: 'buergeramt_slot',
                title: 'Bürgeramt slot available',
                subtitle: count($action->payload['dates'] ?? []).' date(s) at '.($action->payload['office_id'] ?? ''),
                emoji: '📅',
                severity: 'info',
                score: $action->score,
                key: $action->actionKey,
                href: '/bureaucracy',
                meta: ['action_key' => $action->actionKey],
            ),
            'rhine_level' => new Tile(
                type: 'rhine_level',
                title: 'Rhine level: '.($action->payload['level'] ?? '?').'m',
                subtitle: 'Threshold: '.($action->payload['threshold'] ?? ''),
                emoji: '🌊',
                severity: 'warn',
                score: $action->score,
                key: $action->actionKey,
                meta: ['action_key' => $action->actionKey],
            ),
            'market_closure' => new Tile(
                type: 'rhythm_warning',
                title: 'Shops closed: '.($action->payload['day'] ?? ''),
                subtitle: (string) ($action->payload['reason'] ?? ''),
                emoji: '🛒',
                severity: 'neutral',
                score: $action->score,
                key: $action->actionKey,
                meta: ['action_key' => $action->actionKey],
            ),
            // bureaucracy_task bus actions are skipped — the deadline tiles
            // below are computed live on every request, which beats replaying
            // yesterday's 09:00 snapshot.
            default => null,
        };
    }

    private function disruptionTitle(ScoredAction $action): string
    {
        $lines = $action->payload['lines'] ?? [];
        if (empty($lines)) {
            return 'Transit disruption';
        }

        return 'Line '.implode(', ', array_slice($lines, 0, 3)).' disrupted';
    }

    /**
     * Live bureaucracy deadlines — overdue, critical, urgent tiers only.
     *
     * @return list<Tile>
     */
    private function deadlineTiles(HomeContext $context): array
    {
        $tiles = [];

        foreach ($context->openTasks as $userTask) {
            $status = $userTask->deadline_status;
            $urgency = $status['urgency'];

            $score = match ($urgency) {
                'overdue' => self::SCORE_OVERDUE,
                'critical' => self::SCORE_CRITICAL_DEADLINE,
                'urgent' => self::SCORE_URGENT_DEADLINE,
                default => null,
            };

            if ($score === null) {
                continue;
            }

            $tiles[] = new Tile(
                type: 'bureaucracy_deadline',
                title: $userTask->task->title,
                subtitle: $status['label'],
                emoji: '📋',
                severity: $urgency === 'overdue' ? 'danger' : ($urgency === 'critical' ? 'warn' : 'info'),
                score: $score + ($status['days_remaining'] !== null ? -0.01 * max($status['days_remaining'], 0) : 0),
                href: '/bureaucracy',
                key: "task:{$userTask->id}",
                meta: [
                    'user_task_id' => $userTask->id,
                    'urgency' => $urgency,
                    'days_remaining' => $status['days_remaining'],
                ],
            );
        }

        // Cap deadline tiles so one user's backlog doesn't fill the screen.
        usort($tiles, fn (Tile $a, Tile $b) => $b->score <=> $a->score);

        return array_slice($tiles, 0, 3);
    }

    /**
     * German-life rhythm warnings: holiday/Sunday closures.
     *
     * @return list<Tile>
     */
    private function rhythmTiles(): array
    {
        $tiles = [];

        if ($this->holidays->isShopsClosedTomorrow()) {
            $name = $this->holidays->getHolidayName(now()->addDay()) ?? 'Sunday';

            $tiles[] = new Tile(
                type: 'rhythm_warning',
                title: "Tomorrow is {$name} — everything closed",
                subtitle: 'Supermarkets and most shops will be shut. Shop today.',
                emoji: '🛒',
                severity: 'warn',
                score: self::SCORE_RHYTHM_WARNING,
                key: 'shops_closed_tomorrow',
            );
        }

        if ($this->holidays->isHoliday()) {
            $name = $this->holidays->getHolidayName() ?? 'a public holiday';

            $tiles[] = new Tile(
                type: 'rhythm_warning',
                title: "Today is {$name}",
                subtitle: 'Shops are closed; transit runs on a Sunday schedule.',
                emoji: '🇩🇪',
                severity: 'info',
                score: self::SCORE_RHYTHM_WARNING - 10,
                key: 'holiday_today',
            );
        }

        return $tiles;
    }

    /**
     * Tonight's events earn a "Right now" tile ONLY when the soonest is
     * genuinely imminent — otherwise the discovery rail covers them. The tile
     * claims its event so the rail won't repeat it.
     *
     * @return list<Tile>
     */
    private function tonightEventsTile(HomeContext $context): array
    {
        $soon = $context->tonightEvents->first();

        if ($soon === null || $context->now->diffInMinutes($soon->starts_at, false) > self::TONIGHT_URGENT_MINUTES) {
            return [];
        }

        $context->claim("event:{$soon->id}");

        return [new Tile(
            type: 'tonight_events',
            title: 'Soon: '.$soon->title,
            subtitle: $soon->starts_at->format('H:i')
                .($soon->location_name ? " · {$soon->location_name}" : ''),
            emoji: '🎟️',
            severity: 'info',
            score: self::SCORE_TONIGHT_EVENTS,
            href: '/events',
            key: "event:{$soon->id}",
            meta: ['event_id' => $soon->id],
        )];
    }
}
