<?php

namespace App\Console\Commands\Personalisation;

use App\Jobs\RankToday;
use App\Models\User;
use App\Models\UserSetting;
use App\Personalisation\TodayRanker;
use Illuminate\Console\Command;

/** Each morning, Today's AI order for everyone with personalisation on (RankToday), before the day's first look. */
class RankTodays extends Command
{
    protected $signature = 'today:rank {--user= : Only this user id} {--now : Work it out now, not on the queue}';

    protected $description = 'Put Today in order for everyone with personalisation on';

    public function handle(): int
    {
        $count = 0;
        User::query()->whereIn('id', UserSetting::query()->whereRaw("(settings->>'personalisation')::boolean is true")->select('user_id'))
            ->when($this->option('user'), fn ($query, $id) => $query->whereKey((int) $id))
            ->each(function (User $user) use (&$count): void {
                $this->option('now') ? (new RankToday($user->id))->handle(app(TodayRanker::class)) : dispatch(new RankToday($user->id));
                $count++;
            });
        $this->info("Ranked Today for {$count} person(s).");

        return self::SUCCESS;
    }
}
