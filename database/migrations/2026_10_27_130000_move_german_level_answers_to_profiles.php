<?php

use App\Bureaucracy\Facts\FactSourceTrust;
use App\Models\BureaucracyCaseFact;
use App\Models\BureaucracyPerson;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * German level lives on the profile only (owner, 27 Oct 2026). An account holder's own answer
 * kept in their Paperwork record moves to an empty profile; Paperwork stops reading the record's
 * copy either way. A profile that already says something else is left as it is and logged, for
 * the owner to decide. Nothing is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        BureaucracyCaseFact::query()->where('key', 'german_level')->where('state', 'confirmed')->where('answer_state', 'value')
            ->whereNull('superseded_at')->with('case')->orderBy('id')->get()
            ->reject(FactSourceTrust::isSynthetic(...))
            ->groupBy('case_id')
            ->each(function ($facts): void {
                $fact = $facts->last();
                $person = $fact->case?->person_id === null ? null : BureaucracyPerson::query()->find($fact->case->person_id);
                $user = $person?->account_user_id === null ? null : User::query()->find($person->account_user_id);
                if ($user === null || ! is_string($fact->value)) {
                    return;
                }
                if ($user->german_level === null) {
                    $user->forceFill(['german_level' => $fact->value])->saveQuietly();
                } elseif ($user->german_level->value !== $fact->value) {
                    Log::warning('German level differs between profile and Paperwork record; profile kept', ['user_id' => $user->id, 'fact_id' => $fact->id]);
                }
            });
    }

    public function down(): void
    {
        // The profile is the one place it lives now; nothing to move back.
    }
};
