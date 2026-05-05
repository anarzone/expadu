<?php

namespace App\Console\Commands;

use App\Jobs\PrecomputeUserRoutes;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('routes:precompute-all {--user= : Limit to a specific user id}')]
#[Description('Backfill UserRouteCache by dispatching PrecomputeUserRoutes for every onboarded user')]
class PrecomputeAllUserRoutes extends Command
{
    public function handle(): int
    {
        $query = User::query()->whereNotNull('onboarded_at');

        if ($id = $this->option('user')) {
            $query->where('id', (int) $id);
        }

        $count = 0;
        $query->chunk(100, function ($users) use (&$count): void {
            foreach ($users as $user) {
                PrecomputeUserRoutes::dispatch($user->id)->onQueue('commute');
                $count++;
            }
        });

        $this->info("Dispatched PrecomputeUserRoutes for {$count} user(s).");

        return self::SUCCESS;
    }
}
