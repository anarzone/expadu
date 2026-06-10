<?php

namespace App\Home;

use App\ContextEngine\ActionBus;
use App\ContextEngine\ScoredAction;
use App\Models\Event;
use App\Models\User;
use App\Models\UserTask;
use App\Services\GermanHolidayService;

/**
 * Composes the Today screen's urgency-ranked tiles from two sources:
 *
 * 1. The ActionBus — event-driven actions scored by the ContextEngine
 *    (disruptions, delays, weather alerts, Bürgeramt slots, Rhine, markets).
 * 2. Synthetic always-on tiles computed at request time — bureaucracy
 *    deadlines, German-rhythm warnings (holiday/Sunday closures),
 *    tonight's events, and the weekend composer shortcut.
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

    private const SCORE_COMPOSER_SHORTCUT = 30.0;

    private const MAX_TILES = 8;

    public function __construct(
        private ActionBus $bus,
        private GermanHolidayService $holidays,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function tiles(User $user): array
    {
        $tiles = [
            ...$this->busTiles($user),
            ...$this->deadlineTiles($user),
            ...$this->rhythmTiles(),
            ...$this->tonightEventsTile(),
            ...$this->composerShortcutTile(),
        ];

        usort($tiles, fn (Tile $a, Tile $b) => $b->score <=> $a->score);

        return array_map(
            fn (Tile $tile) => $tile->toArray(),
            array_slice($tiles, 0, self::MAX_TILES),
        );
    }

    /**
     * @return list<Tile>
     */
    private function busTiles(User $user): array
    {
        $tiles = [];

        foreach ($this->bus->topK($user->id, 20) as $action) {
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
                meta: ['action_key' => $action->actionKey],
            ),
            'buergeramt_slot' => new Tile(
                type: 'buergeramt_slot',
                title: 'Bürgeramt slot available',
                subtitle: count($action->payload['dates'] ?? []).' date(s) at '.($action->payload['office_id'] ?? ''),
                emoji: '📅',
                severity: 'info',
                score: $action->score,
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
                meta: ['action_key' => $action->actionKey],
            ),
            'market_closure' => new Tile(
                type: 'rhythm_warning',
                title: 'Shops closed: '.($action->payload['day'] ?? ''),
                subtitle: (string) ($action->payload['reason'] ?? ''),
                emoji: '🛒',
                severity: 'neutral',
                score: $action->score,
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
    private function deadlineTiles(User $user): array
    {
        $tiles = [];

        $userTasks = UserTask::query()
            ->where('user_id', $user->id)
            ->open()
            ->notSnoozed()
            ->with('task')
            ->get();

        foreach ($userTasks as $userTask) {
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
            );
        }

        return $tiles;
    }

    /**
     * @return list<Tile>
     */
    private function tonightEventsTile(): array
    {
        $events = Event::query()
            ->whereDate('starts_at', today())
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->limit(4)
            ->get(['id', 'title', 'starts_at', 'location_name']);

        if ($events->isEmpty()) {
            return [];
        }

        $first = $events->first();
        $more = $events->count() - 1;

        return [new Tile(
            type: 'tonight_events',
            title: 'Tonight: '.$first->title,
            subtitle: $first->starts_at->format('H:i')
                .($first->location_name ? " · {$first->location_name}" : '')
                .($more > 0 ? " · +{$more} more" : ''),
            emoji: '🎟️',
            severity: 'neutral',
            score: self::SCORE_TONIGHT_EVENTS,
            href: '/events',
            meta: ['event_id' => $first->id],
        )];
    }

    /**
     * Weekend composer shortcut — Friday 15:00 through Sunday.
     *
     * @return list<Tile>
     */
    private function composerShortcutTile(): array
    {
        $now = now();
        $isWeekendWindow = ($now->isFriday() && $now->hour >= 15)
            || $now->isSaturday()
            || $now->isSunday();

        if (! $isWeekendWindow) {
            return [];
        }

        return [new Tile(
            type: 'composer_shortcut',
            title: 'Plan your weekend',
            subtitle: 'Tell the composer what kind of day you want.',
            emoji: '🗓️',
            severity: 'neutral',
            score: self::SCORE_COMPOSER_SHORTCUT,
            href: '/dashboard#composer',
        )];
    }
}
