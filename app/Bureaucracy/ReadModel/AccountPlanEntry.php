<?php

namespace App\Bureaucracy\ReadModel;

use App\Models\BureaucracyCase;
use App\Models\BureaucracyPerson;
use App\Models\User;

final class AccountPlanEntry
{
    public function __construct(private AccountHolderPlan $plans) {}

    /** An entry point, not a second assessor or an implicit onboarding command. */
    public function for(User $actor): array
    {
        $actor = $actor->fresh();
        abort_unless($actor !== null && $actor->email_verified_at !== null, 403);
        $plan = $this->plans->for($actor);
        $hasRecord = BureaucracyPerson::query()->where('account_user_id', $actor->id)
            ->where(fn ($query) => $query->where('record_status', '!=', 'active')->orWhereHas('dossier'))->exists()
            || BureaucracyCase::query()->where('user_id', $actor->id)->where('status', '!=', 'active')->exists();

        return ['schema_version' => 'bureaucracy.account-entry.1',
            'state' => $plan !== null ? 'ready' : ($hasRecord ? 'record_unavailable' : 'setup_required'),
            'plan' => $plan];
    }
}
