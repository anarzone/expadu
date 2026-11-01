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
        $schema = ['schema_version' => 'bureaucracy.account-entry.1'];
        // Your own record, as you left it: deleted by you, or kept but not used (Art. 18).
        // Neither is assessed.
        $own = BureaucracyPerson::query()->where('account_user_id', $actor->id)->first();
        if ($own?->record_status === 'erased') {
            return [...$schema, 'state' => 'erased', 'erased_at' => $own->erased_at?->toIso8601String(), 'plan' => null];
        }
        if ($own?->record_status === 'active' && $own->processing_stopped_at !== null) {
            return [...$schema, 'state' => 'processing_stopped', 'stopped_at' => $own->processing_stopped_at->toIso8601String(), 'plan' => null];
        }
        $plan = $this->plans->for($actor);
        $hasRecord = BureaucracyPerson::query()->where('account_user_id', $actor->id)
            ->where(fn ($query) => $query->where('record_status', '!=', 'active')->orWhereHas('dossier'))->exists()
            || BureaucracyCase::query()->where('user_id', $actor->id)->where('status', '!=', 'active')->exists();

        return [...$schema,
            'state' => $plan !== null ? 'ready' : ($hasRecord ? 'record_unavailable' : 'setup_required'),
            'plan' => $plan];
    }
}
