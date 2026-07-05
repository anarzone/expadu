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

            $tile = $this->actionToTile($action, $context);
            if ($tile !== null) {
                $tiles[] = $tile;
            }
        }

        return $tiles;
    }

    private function actionToTile(ScoredAction $action, HomeContext $context): ?Tile
    {
        return match ($action->type) {
            'transit_disruption' => $this->disruptionTile($action, $context),
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
            'weather_alert' => $this->weatherTile($action, $context),
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
            // market_closure and bureaucracy_task bus actions are skipped — both
            // are recomputed live every request (rhythmTiles() and deadlineTiles()
            // respectively), which beats replaying an older snapshot. Crucially
            // for market_closure: the synthetic rhythm tile derives from the SAME
            // isShopsClosedTomorrow() predicate, so mapping the bus action too
            // would surface the identical closure twice (the market/holiday dup).
            //
            // buergeramt_slot has no arm because nothing produces it: the live
            // slot-checker was removed (no availability access), so booking is a
            // static deep-link on the bureaucracy task card instead. If a real
            // slot source ever returns, gate its tile on the user having an open
            // task for that office.
            default => null,
        };
    }

    /**
     * Weather fusion. A HAZARD (ice/heat/wind) always earns a tile — it's an
     * environmental danger you can't opt out of. An ADVISORY (rain) earns one
     * only when it threatens what the user is actually doing: an outdoor plan stop
     * or a commute at/after the rain starts. With nothing to spoil it stays on the
     * Alerts record, off Today — a bare "it will rain" is a fact you can verify by
     * looking out the window, not an act-now need.
     */
    private function weatherTile(ScoredAction $action, HomeContext $context): ?Tile
    {
        $alert = $action->payload['alert'] ?? [];
        $title = (string) ($alert['title'] ?? 'Weather alert');

        if (($alert['kind'] ?? 'advisory') === 'hazard') {
            return new Tile(
                type: 'weather_alert',
                title: $title,
                subtitle: (string) ($alert['description'] ?? ''),
                emoji: '🌡️',
                severity: $action->severity === 'critical' ? 'danger' : 'warn',
                score: $action->score,
                key: $action->actionKey,
                meta: ['action_key' => $action->actionKey],
            );
        }

        $from = $alert['from'] ?? null;
        $rainAt = (! is_string($from) || $from === '' || $from === 'now')
            ? $context->now
            : $context->now->setTimeFromTimeString($from);

        $exposure = $context->outdoorExposureAfter($rainAt);
        if ($exposure === null) {
            return null;
        }

        return new Tile(
            type: 'weather_alert',
            title: $title,
            subtitle: ucfirst($exposure).' may get caught in the rain — plan around it.',
            emoji: '🌧️',
            severity: 'warn',
            score: $action->score,
            key: $action->actionKey,
            meta: ['action_key' => $action->actionKey, 'exposure' => $exposure],
        );
    }

    /**
     * A disruption tile, framed by how much it touches the user. Only reaches
     * here when it's dashboard-worthy (their line, a saved place, or a critical
     * strike — see TransitDisruptionEvaluator). The subtitle escalates: a line
     * they ride AND a commute today → an act-now "leave early"; their line but no
     * trip today → ambient "one of your lines"; otherwise the city-wide notice.
     */
    private function disruptionTile(ScoredAction $action, HomeContext $context): Tile
    {
        $onUserLine = (bool) ($action->payload['on_user_line'] ?? false);
        $onRouteToday = $onUserLine && $context->hasLiveCommuteToday();

        $subtitle = match (true) {
            $onRouteToday => 'On your route today — leave early or reroute.',
            $action->severity === 'critical' => 'Major impact — check before you leave',
            $onUserLine => "It's on one of your lines",
            default => 'Affects stops near your places',
        };

        return new Tile(
            type: 'transit_disruption',
            title: $this->disruptionTitle($action),
            subtitle: $subtitle,
            emoji: '⚠️',
            severity: $action->severity === 'critical' ? 'danger' : 'warn',
            score: $action->score,
            key: $action->actionKey,
            href: '/alerts',
            meta: [
                'lines' => $action->payload['lines'] ?? [],
                'action_key' => $action->actionKey,
                'on_route_today' => $onRouteToday,
            ],
        );
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
