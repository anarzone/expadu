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
 * Fires a synthetic TransitDisruptionDetected aimed at one dedicated canary
 * user, polls pending_actions:{id} for up to 10 sec, asserts an action
 * appeared, then ZREMs the synthetic entry.
 *
 * The canary user is never onboarded, so no real evaluator or stat counts
 * it, and the event is tagged synthetic so it reaches that user alone and
 * writes no Alert. It used to target a random real user with a broadcast
 * 'major' event, which wrote a fake disruption alert for every onboarded
 * user every 30 minutes.
 *
 * Failure throws — Sentry catches it and pages on-call.
 */
#[Signature('controls:synthetic-disruption {--user= : Force a specific user id} {--timeout=10 : Seconds to poll for the action}')]
#[Description('End-to-end canary: synthesize a disruption, assert it materializes in the action bus.')]
class SyntheticDisruption extends Command
{
    private const SYNTHETIC_ID = 999_999_999;

    public const CANARY_EMAIL = 'synthetic-canary@expadu.com';

    public function handle(ActionBus $bus, UserTransitLinesService $transitLines): int
    {
        $user = $this->canaryUser();
        if (! $user) {
            $this->error("No user with id {$this->option('user')}.");

            return self::FAILURE;
        }

        // Use a line that serves the user's places when it has any so the
        // line-match path fires; the canary user has none, so it takes the
        // major-severity broadcast path (still end-to-end).
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
            syntheticUserId: $user->id,
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

    private function canaryUser(): ?User
    {
        if ($id = $this->option('user')) {
            return User::find((int) $id);
        }

        // Never onboarded: every evaluator and onboarding stat filters on
        // onboarded_at, so this user only ever sees the canary's own event.
        return User::query()->firstOrCreate(
            ['email' => self::CANARY_EMAIL],
            ['name' => 'Synthetic canary', 'password' => bcrypt(str()->random(40))],
        );
    }
}
