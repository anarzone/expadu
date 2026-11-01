<?php

namespace App\Console\Commands\Notifications;

use App\ContextEngine\ActionBus;
use App\ContextEngine\PlanRisks;
use App\ContextEngine\ScoredAction;
use App\Models\ComposerWorkspace;
use App\Models\User;
use App\Notifications\PlanStopRiskNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * What threatens the stops of today's plans (PlanRisks), every five minutes: a stop starting
 * within two hours that's listed closed, has rain or a hazard while they're outdoors, or a
 * disruption or delay near it pushes once (coordinator, 26 Oct 2026: tier 1 only that close),
 * through the one gate (ActionBus::deliver): their preference, quiet hours, the day's budget, once per
 * situation (that stop and risk; a worse one again).
 */
class WatchPlans extends Command
{
    protected $signature = 'notifications:watch-plans {--user= : Only this user id}';

    protected $description = 'Push what threatens a stop of today\'s plan starting within two hours';

    /** Each risk's push type, for their preferences (NotificationThrottle). */
    private const TYPES = ['disruption' => 'plan_disruption', 'delay' => 'plan_delay', 'rain' => 'plan_weather', 'hazard' => 'plan_weather', 'closed' => 'plan_closed'];

    private const RANK = ['critical' => 4, 'major' => 3, 'moderate' => 2, 'minor' => 1];

    public function handle(PlanRisks $risks, ActionBus $bus): int
    {
        $now = CarbonImmutable::now('Europe/Berlin');
        $sent = 0;
        $failed = 0;

        User::query()->whereHas('pushSubscriptions')->whereIn('id', ComposerWorkspace::query()->select('user_id'))
            ->when($this->option('user'), fn ($query, $id) => $query->whereKey((int) $id))
            ->each(function (User $user) use ($risks, $bus, $now, &$sent, &$failed): void {
                // One push a stop, its worst risk first; once per stop, again only when it gets worse.
                $soon = collect($risks->for($user, $now))
                    ->filter(fn (array $risk): bool => $risk['at']->greaterThan($now) && $risk['at']->lessThanOrEqualTo($now->addMinutes(PlanRisks::SOON_MINUTES)))
                    ->groupBy('slot');
                foreach ($soon as $slot => $stop) {
                    $stop = $stop->sortByDesc(fn (array $risk): int => self::RANK[$risk['severity']] ?? 0)->values();
                    $worst = $stop->first();
                    try {
                        $decision = $bus->deliver($user,
                            ScoredAction::claimed(self::TYPES[$worst['kind']], "plan_risk:{$user->id}:{$now->toDateString()}:{$slot}", $worst['severity'], $worst['at']->addHours(2)),
                            new PlanStopRiskNotification($worst['text'], $worst['start'], $stop->skip(1)->pluck('text')->all()), $worst['at']);
                        $sent += $decision->send ? 1 : 0;
                    } catch (Throwable $e) {
                        $failed++;
                        report($e);
                    }
                }
            });

        $this->info("Sent {$sent} plan risk(s).".($failed > 0 ? " {$failed} failed." : ''));

        return self::SUCCESS;
    }
}
