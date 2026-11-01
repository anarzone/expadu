<?php

namespace App\Console\Commands\Notifications;

use App\ContextEngine\ActionBus;
use App\ContextEngine\BriefText;
use App\ContextEngine\DayBriefs;
use App\ContextEngine\ScoredAction;
use App\Models\User;
use App\Notifications\DayBriefNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The day's briefs (DayBriefs), every five minutes: for each person with a push subscription, the
 * brief due now, once. Each is claimed before it's sent, so two runs never send it twice; one held
 * by the push gate (the gap between pushes, the hour's budget) or failing gives its claim back and
 * goes on a later run, inside its window; one with nothing to say, or dropped (their preference,
 * the day's budget), stays claimed.
 */
class SendDayBriefs extends Command
{
    protected $signature = 'notifications:send-briefs {--user= : Only this user id}';

    protected $description = 'Send the day\'s brief due now: the morning one, or the evening before an important day';

    public function handle(DayBriefs $briefs, BriefText $text, ActionBus $bus): int
    {
        $now = CarbonImmutable::now('Europe/Berlin');
        $counts = ['sent' => 0, 'held' => 0, 'dropped' => 0, 'empty' => 0, 'failed' => 0];

        User::query()->whereHas('pushSubscriptions')
            ->when($this->option('user'), fn ($query, $id) => $query->whereKey((int) $id))
            ->each(function (User $user) use ($briefs, $text, $bus, $now, &$counts): void {
                $due = $briefs->due($user, $now);
                if ($due === null) {
                    return;
                }
                $claim = "day_brief:{$user->id}:{$due['day']->toDateString()}:{$due['kind']}";
                if (! Cache::add($claim, true, now()->addDays(2))) {
                    return;
                }
                $items = $briefs->items($user, $due['day'], $due['kind']);
                if ($items === []) {
                    $counts['empty']++;

                    return;
                }
                try {
                    $brief = $text->write($user, $items, $due['day'], $due['kind']);
                    $decision = $bus->deliver($user,
                        ScoredAction::claimed('day_brief', $claim, 'info', $due['at']->addHours(2), ['kind' => $due['kind'], 'by' => $brief['by']]),
                        new DayBriefNotification($brief['title'], $brief['lines']), $due['at']);
                } catch (Throwable $e) {
                    Cache::forget($claim);
                    $counts['failed']++;
                    report($e);

                    return;
                }
                if ($decision->later) {
                    Cache::forget($claim);
                }
                $counts[$decision->send ? 'sent' : ($decision->later ? 'held' : 'dropped')]++;
            });

        $this->info(collect($counts)->map(fn (int $n, string $what): string => "{$what}={$n}")->implode(' '));
        if ($counts['sent'] + $counts['failed'] > 0) {
            Log::info('Day briefs', $counts);
        }

        return self::SUCCESS;
    }
}
