<?php

namespace App\Bureaucracy\Cases;

use App\Bureaucracy\Facts\CaseFactStore;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseFact;
use App\Models\BureaucracyFactConflict;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ResolveCaseConflict
{
    public function __construct(private CaseFactStore $factStore) {}

    public function resolve(
        User $user,
        BureaucracyFactConflict $conflict,
        string $choice,
    ): BureaucracyCaseFact {
        return DB::transaction(function () use ($user, $conflict, $choice): BureaucracyCaseFact {
            $caseId = BureaucracyFactConflict::query()
                ->whereKey($conflict->getKey())
                ->valueOrFail('case_id');
            $case = BureaucracyCase::query()
                ->whereKey($caseId)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $case->user_id !== (int) $user->getKey()) {
                throw new AuthorizationException;
            }

            $lockedConflict = BureaucracyFactConflict::query()
                ->whereKey($conflict->getKey())
                ->where('case_id', $case->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedConflict->status !== 'unresolved') {
                throw new AuthorizationException;
            }

            $chosenFactId = $choice === 'existing'
                ? $lockedConflict->existing_fact_id
                : $lockedConflict->candidate_fact_id;
            $chosenFact = BureaucracyCaseFact::query()
                ->whereKey($chosenFactId)
                ->where('case_id', $case->getKey())
                ->where('key', $lockedConflict->fact_key)
                ->firstOrFail();

            return $this->factStore->resolveConflict($lockedConflict, $chosenFact);
        });
    }
}
