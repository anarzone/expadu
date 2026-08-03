<?php

namespace App\Bureaucracy\Facts;

use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseFact;
use App\Models\BureaucracyFactConflict;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final class CaseFactStore
{
    public function __construct(private FactRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $facts
     */
    public function bootstrapConfirmedFacts(
        User $user,
        array $facts,
        string $source = 'legacy_profile',
    ): BureaucracyCase {
        return DB::transaction(function () use ($user, $facts, $source): BureaucracyCase {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $case = BureaucracyCase::query()->firstOrCreate(
                ['user_id' => $lockedUser->getKey()],
                ['status' => 'active'],
            );

            $lockedCase = BureaucracyCase::query()
                ->whereKey($case->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            foreach ($facts as $key => $value) {
                if ($value === null) {
                    continue;
                }

                $normalizedValue = $this->registry->definition($key)->normalize($value);

                $existingFact = BureaucracyCaseFact::query()
                    ->where('case_id', $lockedCase->getKey())
                    ->where('key', $key)
                    ->where('state', 'confirmed')
                    ->lockForUpdate()
                    ->first();

                if ($existingFact !== null) {
                    continue;
                }

                BureaucracyCaseFact::query()->create([
                    'case_id' => $lockedCase->getKey(),
                    'key' => $key,
                    'value' => $normalizedValue,
                    'state' => 'confirmed',
                    'source' => $source,
                    ...$this->confirmationTimestamps($key),
                ]);
            }

            return $lockedCase->fresh();
        });
    }

    public function confirmedFact(
        BureaucracyCase $case,
        string $key,
        bool $forHighImpact = true,
    ): ?BureaucracyCaseFact {
        $this->registry->definition($key);

        return BureaucracyCaseFact::query()
            ->where('case_id', $case->getKey())
            ->where('key', $key)
            ->where('state', 'confirmed')
            ->when($forHighImpact, fn ($query) => $query->where(function ($query) {
                $query->whereNull('reconfirm_at')
                    ->orWhere('reconfirm_at', '>', now());
            }))
            ->first();
    }

    public function recordCandidate(
        BureaucracyCase $case,
        string $key,
        mixed $value,
        string $source,
        ?string $sourceReference = null,
    ): BureaucracyCaseFact {
        $normalizedValue = $this->registry->definition($key)->normalize($value);

        return DB::transaction(function () use (
            $case,
            $key,
            $normalizedValue,
            $source,
            $sourceReference,
        ): BureaucracyCaseFact {
            $lockedCase = BureaucracyCase::query()
                ->whereKey($case->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            return BureaucracyCaseFact::query()->create([
                'case_id' => $lockedCase->getKey(),
                'key' => $key,
                'value' => $normalizedValue,
                'state' => 'candidate',
                'source' => $source,
                'source_reference' => $sourceReference,
            ]);
        });
    }

    public function confirmCandidate(BureaucracyCaseFact $candidate): ?BureaucracyFactConflict
    {
        return DB::transaction(function () use ($candidate): ?BureaucracyFactConflict {
            $lockedCase = BureaucracyCase::query()
                ->whereKey($candidate->case_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedCandidate = BureaucracyCaseFact::query()
                ->whereKey($candidate->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedCandidate->state === 'confirmed') {
                return null;
            }

            if ($lockedCandidate->state !== 'candidate') {
                throw new DomainException('Only a candidate fact may be confirmed.');
            }

            $this->registry->definition($lockedCandidate->key)->normalize($lockedCandidate->value);

            $existingFact = BureaucracyCaseFact::query()
                ->where('case_id', $lockedCase->getKey())
                ->where('key', $lockedCandidate->key)
                ->where('state', 'confirmed')
                ->whereKeyNot($lockedCandidate->getKey())
                ->lockForUpdate()
                ->first();

            if ($existingFact === null) {
                $this->confirmFact($lockedCandidate);
                $lockedCase->increment('fact_version');

                return null;
            }

            if ($existingFact->value === $lockedCandidate->value) {
                $this->supersedeFact($existingFact);
                $this->confirmFact($lockedCandidate);

                return null;
            }

            $existingConflict = BureaucracyFactConflict::query()
                ->where('case_id', $lockedCase->getKey())
                ->where('fact_key', $lockedCandidate->key)
                ->where('existing_fact_id', $existingFact->getKey())
                ->where('candidate_fact_id', $lockedCandidate->getKey())
                ->where('status', 'unresolved')
                ->lockForUpdate()
                ->first();

            if ($existingConflict !== null) {
                return $existingConflict;
            }

            return BureaucracyFactConflict::query()->create([
                'case_id' => $lockedCase->getKey(),
                'fact_key' => $lockedCandidate->key,
                'existing_fact_id' => $existingFact->getKey(),
                'candidate_fact_id' => $lockedCandidate->getKey(),
                'status' => 'unresolved',
            ]);
        });
    }

    public function resolveConflict(
        BureaucracyFactConflict $conflict,
        BureaucracyCaseFact $chosenFact,
    ): BureaucracyCaseFact {
        return DB::transaction(function () use ($conflict, $chosenFact): BureaucracyCaseFact {
            $lockedCase = BureaucracyCase::query()
                ->whereKey($conflict->case_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedConflict = BureaucracyFactConflict::query()
                ->whereKey($conflict->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedConflict->status === 'resolved') {
                return BureaucracyCaseFact::query()->findOrFail($lockedConflict->resolved_fact_id);
            }

            $allowedFactIds = [
                (int) $lockedConflict->existing_fact_id,
                (int) $lockedConflict->candidate_fact_id,
            ];

            if (! in_array((int) $chosenFact->getKey(), $allowedFactIds, true)) {
                throw new DomainException('The chosen fact does not belong to this conflict.');
            }

            $facts = BureaucracyCaseFact::query()
                ->whereIn('id', $allowedFactIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $winner = $facts->get($chosenFact->getKey());
            $loserId = (int) $chosenFact->getKey() === (int) $lockedConflict->existing_fact_id
                ? (int) $lockedConflict->candidate_fact_id
                : (int) $lockedConflict->existing_fact_id;
            $loser = $facts->get($loserId);

            if (! $winner instanceof BureaucracyCaseFact || ! $loser instanceof BureaucracyCaseFact) {
                throw new DomainException('The conflict references unavailable facts.');
            }

            if (
                (int) $winner->case_id !== (int) $lockedCase->getKey()
                || (int) $loser->case_id !== (int) $lockedCase->getKey()
                || $winner->key !== $lockedConflict->fact_key
                || $loser->key !== $lockedConflict->fact_key
            ) {
                throw new DomainException('The conflict references facts from another case or key.');
            }

            $this->supersedeFact($loser);
            $this->confirmFact($winner);

            $lockedConflict->update([
                'status' => 'resolved',
                'resolved_fact_id' => $winner->getKey(),
                'resolved_at' => now(),
            ]);

            $lockedCase->increment('fact_version');

            return $winner->fresh();
        });
    }

    private function confirmFact(BureaucracyCaseFact $fact): void
    {
        $fact->update([
            'state' => 'confirmed',
            'superseded_at' => null,
            ...$this->confirmationTimestamps($fact->key),
        ]);
    }

    private function supersedeFact(BureaucracyCaseFact $fact): void
    {
        $fact->update([
            'state' => 'superseded',
            'superseded_at' => now(),
        ]);
    }

    /**
     * @return array{confirmed_at: mixed, reconfirm_at: mixed}
     */
    private function confirmationTimestamps(string $key): array
    {
        $confirmedAt = now();

        return [
            'confirmed_at' => $confirmedAt,
            'reconfirm_at' => $confirmedAt->copy()->addDays(
                $this->registry->definition($key)->reconfirmAfterDays,
            ),
        ];
    }
}
