<?php

namespace App\Console\Commands\Notifications;

use App\ContextEngine\DayImportance;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Each morning at 05:30, how important today is for everyone who gets pushes (DayImportance),
 * worked out fresh before the day's first push or brief asks; it's worked out again on any change.
 * Prints how many days are quiet, normal and important, never whose.
 */
class ScoreDays extends Command
{
    protected $signature = 'notifications:score-days {--user= : Only this user id} {--date= : The day (Y-m-d), default today}';

    protected $description = 'Work out how important today is for everyone with a push subscription';

    public function handle(DayImportance $importance): int
    {
        $day = $this->option('date') ? CarbonImmutable::parse((string) $this->option('date'), 'Europe/Berlin') : CarbonImmutable::now('Europe/Berlin');
        $bands = [DayImportance::QUIET => 0, DayImportance::NORMAL => 0, DayImportance::IMPORTANT => 0];

        User::query()->whereHas('pushSubscriptions')
            ->when($this->option('user'), fn ($query, $id) => $query->whereKey((int) $id))
            ->each(function (User $user) use ($importance, $day, &$bands): void {
                DayImportance::forget($user->id, $day);
                $scored = $importance->for($user, $day);
                $bands[$scored['band']]++;
                if ($this->option('user')) {
                    $this->line(json_encode($scored, JSON_UNESCAPED_UNICODE));
                }
            });

        $this->info($day->toDateString().': '.collect($bands)->map(fn (int $n, string $band): string => "{$band}={$n}")->implode(' '));

        return self::SUCCESS;
    }
}
