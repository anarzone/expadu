<?php

namespace App\Jobs;

use App\Models\User;
use App\Personalisation\TodayRanker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Today's AI order for one person (TodayRanker), in the background: Today never waits on the
 * model. Once each morning (today:rank) and again a little after they look at something new
 * (SignalRecorder), at most once in a quarter of an hour.
 */
class RankToday implements ShouldQueue
{
    use Queueable;

    /** At most this often after new signals. */
    private const QUIET_MINUTES = 15;

    public function __construct(public readonly int $userId)
    {
        // Prod's worker only consumes redis; AI calls go on the llm queue.
        $this->onConnection('redis')->onQueue('llm');
    }

    /** A little after something new, once a quarter of an hour at most. */
    public static function soon(int $userId): void
    {
        if (Cache::add("today_rank:soon:{$userId}", true, now()->addMinutes(self::QUIET_MINUTES))) {
            dispatch(new self($userId))->delay(now()->addMinutes(2));
        }
    }

    public function handle(TodayRanker $ranker): void
    {
        $user = User::query()->find($this->userId);
        if ($user !== null) {
            $ranker->rank($user);
        }
    }
}
