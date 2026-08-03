<?php

namespace App\Bureaucracy\QA;

use App\Bureaucracy\Facts\FactRegistry;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseFact;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ScenarioFactSynchronizer
{
    public function __construct(private FactRegistry $registry) {}

    /**
     * Synchronize the deterministic facts owned by a QA scenario. Ordinary
     * personas retire those facts so a previous simulation cannot leak into
     * the next one. Re-running the same scenario writes no new fact rows.
     *
     * @param  array<string, mixed>  $persona
     */
    public function sync(User $user, array $persona): ?BureaucracyCase
    {
        return DB::transaction(function () use ($user, $persona): ?BureaucracyCase {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $facts = $this->normalizedFacts((array) ($persona['facts'] ?? []));
            $source = 'qa_scenario:'.$persona['key'];
            $case = BureaucracyCase::query()->where('user_id', $lockedUser->getKey())->first();

            if ($case === null && $facts === []) {
                return null;
            }

            $case ??= BureaucracyCase::query()->create([
                'user_id' => $lockedUser->getKey(),
                'status' => 'active',
            ]);
            $lockedCase = BureaucracyCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();
            $changed = false;

            $activeScenarioFacts = BureaucracyCaseFact::query()
                ->where('case_id', $lockedCase->getKey())
                ->where('state', 'confirmed')
                ->where('source', 'like', 'qa_scenario:%')
                ->lockForUpdate()
                ->get();

            foreach ($activeScenarioFacts as $fact) {
                if ($fact->source === $source
                    && array_key_exists($fact->key, $facts)
                    && $fact->value === $facts[$fact->key]
                    && ($fact->reconfirm_at === null || $fact->reconfirm_at->isFuture())) {
                    continue;
                }

                $this->supersede($fact);
                $changed = true;
            }

            foreach ($facts as $key => $value) {
                $existing = BureaucracyCaseFact::query()
                    ->where('case_id', $lockedCase->getKey())
                    ->where('key', $key)
                    ->where('state', 'confirmed')
                    ->where('source', $source)
                    ->lockForUpdate()
                    ->first();

                if ($existing?->value === $value) {
                    $otherConfirmedFacts = BureaucracyCaseFact::query()
                        ->where('case_id', $lockedCase->getKey())
                        ->where('key', $key)
                        ->where('state', 'confirmed')
                        ->whereKeyNot($existing->getKey())
                        ->lockForUpdate()
                        ->get();

                    if ($otherConfirmedFacts->isNotEmpty()) {
                        $otherConfirmedFacts->each(function (BureaucracyCaseFact $fact): void {
                            $this->supersede($fact);
                        });
                        $changed = true;
                    }

                    continue;
                }

                BureaucracyCaseFact::query()
                    ->where('case_id', $lockedCase->getKey())
                    ->where('key', $key)
                    ->where('state', 'confirmed')
                    ->lockForUpdate()
                    ->get()
                    ->each(function (BureaucracyCaseFact $fact): void {
                        $this->supersede($fact);
                    });

                $confirmedAt = now();
                BureaucracyCaseFact::query()->create([
                    'case_id' => $lockedCase->getKey(),
                    'key' => $key,
                    'value' => $value,
                    'state' => 'confirmed',
                    'source' => $source,
                    'confirmed_at' => $confirmedAt,
                    'reconfirm_at' => $confirmedAt->copy()->addDays(
                        $this->registry->definition($key)->reconfirmAfterDays,
                    ),
                ]);
                $changed = true;
            }

            if ($changed) {
                $lockedCase->increment('fact_version');
            }

            return $lockedCase->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private function normalizedFacts(array $facts): array
    {
        $normalized = [];

        foreach ($facts as $key => $value) {
            $normalized[$key] = $this->registry->definition($key)->normalize($value);
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    private function supersede(BureaucracyCaseFact $fact): void
    {
        $fact->update([
            'state' => 'superseded',
            'superseded_at' => now(),
        ]);
    }
}
