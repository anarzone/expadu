<?php

namespace App\Console\Commands\Controls;

use App\ContextEngine\ActionBus;
use App\Events\Context\TransitDisruptionDetected;
use App\Models\User;
use App\Services\UserTransitLinesService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Section D2 — synthetic canary. Catches silent breakage where the
 * pipeline is wired but events stop landing in the bus (queue worker
 * died, listener deregistered, evaluator returns null on every user, …).
 *
 * Picks a random onboarded user with a saved place, fires a synthetic
 * TransitDisruptionDetected with a line that actually serves a stop near
 * that place, polls pending_actions:{id} for up to 10 sec, asserts an
 * action appeared, then ZREMs the synthetic entry so it doesn't pollute
 * the user's real dashboard.
 *
 * Failure throws — Sentry catches it and pages on-call.
 */
#[Signature('controls:synthetic-disruption {--user= : Force a specific user id} {--timeout=10 : Seconds to poll for the action}')]
#[Description('End-to-end canary: synthesize a disruption, assert it materializes in the action bus.')]
class SyntheticDisruption extends Command
{
    private const SYNTHETIC_ID = 999_999_999;

    public function handle(ActionBus $bus, UserTransitLinesService $transitLines): int
    {
        $user = $this->pickUser();
        if (! $user) {
            $this->warn('No onboarded user with a saved place yet — skipping (this is OK pre-launch).');

            return self::SUCCESS;
        }

        // Use a line that actually serves the user's places so the
        // line-match path fires; fall back to the major-severity broadcast
        // path when the user has no resolvable lines (still end-to-end).
        $lines = $transitLines->getRelevantLines($user)['lines'];
        $line = (string) ($lines->first() ?? 'SYNTHETIC');

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

        if ($found === null) {
            $msg = "synthetic disruption never landed in pending_actions:{$user->id} within {$timeout}s";
            Log::error('controls:synthetic-disruption failed', ['user_id' => $user->id, 'line' => $line]);
            throw new \RuntimeException($msg);
        }

        // Score floor checks the scoring dimensions multiplied. A major
        // disruption scores severity_base(70) × relevance × temporal(1.0):
        // line match → 70 × 0.8 = 56, broadcast fallback → 70 × 0.3 = 21.
        // Either way > 5 proves the pipeline scored rather than zeroing out.
        if ($found->score <= 5.0) {
            $msg = "synthetic disruption scored {$found->score} (expected > 5)";
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
            ->whereHas('places', function ($q) {
                $q->whereNotNull('lat')->whereNotNull('lng');
            })
            ->inRandomOrder()
            ->first();
    }
}
