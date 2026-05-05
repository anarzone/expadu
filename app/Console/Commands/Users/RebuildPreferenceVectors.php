<?php

namespace App\Console\Commands\Users;

use App\Models\Spot;
use App\Models\User;
use App\Models\UserEvent;
use App\Services\EmbeddingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuild users.preference_vector by averaging embeddings of items the
 * user has engaged with, weighted by event type and decayed over time.
 *
 * Run daily via the scheduler.
 *
 * Cold-start fallback: when the user has no engagement history, seed a
 * vector from their onboarding profile (situation + german_level + city).
 */
#[Signature('users:rebuild-preference-vectors {--user= : Limit to a specific user id} {--days=90 : Lookback window for engagement events}')]
#[Description('Recompute users.preference_vector from recent engagement events.')]
class RebuildPreferenceVectors extends Command
{
    private const WEIGHTS = [
        'event_saved' => 10.0,
        'journey_planned' => 5.0,
        'spot_viewed' => 3.0,
        'card_clicked' => 2.0,
        'departure_viewed' => 1.0,
    ];

    private const DECAY = 0.97;

    public function handle(EmbeddingService $service): int
    {
        $days = (int) $this->option('days');
        $userId = $this->option('user');

        $query = User::query();
        if ($userId !== null) {
            $query->where('id', (int) $userId);
        } else {
            $query->whereNotNull('onboarded_at');
        }

        $count = 0;
        $updated = 0;
        $coldStart = 0;

        $query->chunkById(50, function ($users) use ($service, $days, &$count, &$updated, &$coldStart): void {
            foreach ($users as $user) {
                $count++;
                $vector = $this->buildVectorFromEngagement($user, $days);

                if ($vector === null) {
                    $vector = $this->coldStartVector($user, $service);
                    if ($vector !== null) {
                        $coldStart++;
                    }
                }

                if ($vector === null) {
                    continue;
                }

                DB::table('users')->where('id', $user->id)->update([
                    'preference_vector' => DB::raw("'".EmbeddingService::toLiteral($vector)."'::vector"),
                    'preference_vector_updated_at' => now(),
                ]);
                $updated++;
            }
        });

        $this->info("Processed {$count} user(s); updated {$updated}; cold-start {$coldStart}");

        return self::SUCCESS;
    }

    /** @return list<float>|null */
    private function buildVectorFromEngagement(User $user, int $days): ?array
    {
        $events = UserEvent::where('user_id', $user->id)
            ->whereIn('event_type', array_keys(self::WEIGHTS))
            ->where('created_at', '>', now()->subDays($days))
            ->get(['event_type', 'payload', 'created_at']);

        if ($events->isEmpty()) {
            return null;
        }

        $sum = null;
        $totalWeight = 0.0;

        foreach ($events as $event) {
            $weight = self::WEIGHTS[$event->event_type] ?? 0.0;
            if ($weight <= 0.0) {
                continue;
            }
            $daysOld = max(0, now()->diffInDays($event->created_at, true));
            $weight *= self::DECAY ** $daysOld;

            $vec = $this->vectorForEvent($event);
            if ($vec === null) {
                continue;
            }

            if ($sum === null) {
                $sum = array_fill(0, count($vec), 0.0);
            }
            foreach ($vec as $i => $v) {
                $sum[$i] += $weight * $v;
            }
            $totalWeight += $weight;
        }

        if ($sum === null || $totalWeight <= 0.0) {
            return null;
        }

        return array_map(fn (float $x) => $x / $totalWeight, $sum);
    }

    /** @return list<float>|null */
    private function vectorForEvent(UserEvent $event): ?array
    {
        $payload = is_array($event->payload) ? $event->payload : [];
        $spotId = $payload['spot_id'] ?? null;
        if (! $spotId) {
            return null;
        }

        $row = Spot::find((int) $spotId);
        $raw = $row?->embedding;
        if (! $raw) {
            return null;
        }

        return $this->parseLiteral((string) $raw);
    }

    /** @return list<float>|null */
    private function coldStartVector(User $user, EmbeddingService $service): ?array
    {
        $situation = $user->situation?->value ?? null;
        if (! $situation) {
            return null;
        }

        $text = trim(implode(' ', array_filter([
            'expat in '.$user->city,
            'situation: '.str_replace('_', ' ', $situation),
            'german level: '.($user->german_level?->value ?? 'unknown'),
        ])));

        return $service->embed($text);
    }

    /** @return list<float>|null */
    private function parseLiteral(string $literal): ?array
    {
        $literal = trim($literal, "[] \n\r\t");
        if ($literal === '') {
            return null;
        }
        $parts = explode(',', $literal);

        return array_map('floatval', $parts);
    }
}
