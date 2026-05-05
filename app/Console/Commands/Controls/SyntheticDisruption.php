<?php

namespace App\Console\Commands\Controls;

use App\ContextEngine\ActionBus;
use App\Events\Context\TransitDisruptionDetected;
use App\Models\User;
use App\Models\UserRouteCache;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Section D2 — synthetic canary. Catches silent breakage where the
 * pipeline is wired but events stop landing in the bus (queue worker
 * died, listener deregistered, evaluator returns null on every user, …).
 *
 * Picks a random onboarded user with a UserRouteCache row, fires a
 * synthetic TransitDisruptionDetected with a line that user actually
 * uses, polls pending_actions:{id} for up to 10 sec, asserts an action
 * appeared with score > 50, then ZREMs the synthetic entry so it doesn't
 * pollute the user's real dashboard.
 *
 * Failure throws — Sentry catches it and pages on-call.
 */
#[Signature('controls:synthetic-disruption {--user= : Force a specific user id} {--timeout=10 : Seconds to poll for the action}')]
#[Description('End-to-end canary: synthesize a disruption, assert it materializes in the action bus.')]
class SyntheticDisruption extends Command
{
    private const SYNTHETIC_ID = 999_999_999;

    public function handle(ActionBus $bus): int
    {
        $user = $this->pickUser();
        if (! $user) {
            $this->warn('No onboarded user with UserRouteCache yet — skipping (this is OK pre-cutover).');

            return self::SUCCESS;
        }

        /** @var UserRouteCache|null $route */
        $route = UserRouteCache::where('user_id', $user->id)
            ->whereNotNull('lines')
            ->first();
        $lines = $route ? (array) ($route->lines ?? []) : [];
        if (empty($lines)) {
            $this->warn("User {$user->id} has UserRouteCache but no lines — skipping.");

            return self::SUCCESS;
        }
        $line = (string) $lines[0];

        $actionKey = 'disruption:'.self::SYNTHETIC_ID.':user:'.$user->id;

        // Pre-clean any leftover from a prior failed run
        $bus->remove($user->id, $actionKey);

        $this->info("Firing synthetic disruption — user={$user->id} line={$line}");

        $startedAt = microtime(true);

        event(new TransitDisruptionDetected(
            disruptionId: self::SYNTHETIC_ID,
            lines: [$line],
            stopsAffected: [],
            severity: 'major',
            bbox: null,
            expiresAt: now()->addMinutes(15),
        ));

        $timeout = (int) $this->option('timeout');
        $deadline = microtime(true) + $timeout;
        $found = null;

        while (microtime(true) < $deadline) {
            foreach ($bus->topK($user->id, 20) as $action) {
                if ($action->actionKey === $actionKey) {
                    $found = $action;
                    break 2;
                }
            }
            usleep(250_000);
        }

        // Always clean up before asserting, so a failure does not leave gunk
        $bus->remove($user->id, $actionKey);
        $bus->remove($user->id, 'disruption_alt:'.self::SYNTHETIC_ID.':user:'.$user->id.':route:'.($route->id ?? 0));

        if ($found === null) {
            $msg = "synthetic disruption never landed in pending_actions:{$user->id} within {$timeout}s";
            Log::error('controls:synthetic-disruption failed', ['user_id' => $user->id, 'line' => $line]);
            throw new \RuntimeException($msg);
        }

        if ($found->score <= 50.0) {
            $msg = "synthetic disruption scored {$found->score} (expected > 50)";
            Log::error('controls:synthetic-disruption low score', [
                'user_id' => $user->id,
                'score' => $found->score,
            ]);
            throw new \RuntimeException($msg);
        }

        $this->info(sprintf(
            'OK — action=%s score=%.1f within %.2fs',
            $found->actionKey,
            $found->score,
            microtime(true) - $startedAt,
        ));

        return self::SUCCESS;
    }

    private function pickUser(): ?User
    {
        if ($id = $this->option('user')) {
            return User::find((int) $id);
        }

        return User::query()
            ->whereNotNull('onboarded_at')
            ->whereExists(function ($q) {
                $q->select('id')
                    ->from('user_route_caches')
                    ->whereColumn('user_route_caches.user_id', 'users.id');
            })
            ->inRandomOrder()
            ->first();
    }
}
