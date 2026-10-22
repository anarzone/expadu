<?php

namespace App\Profile;

use App\Models\BureaucracyPerson;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Testing tool behind `user:reset-journey` and the admin's "Reset journey":
 * wipes a legacy discovery profile so its journey can be replayed.
 *
 * Legacy profiles only. An account with a Paperwork record (dossier or
 * person) must use the correction or erasure flow, so it is refused and
 * nothing changes.
 */
final class ResetLegacyJourney
{
    /** @return bool false when the account has a Paperwork record and nothing was reset */
    public function execute(User $user, bool $keepTasks = false): bool
    {
        return DB::transaction(function () use ($user, $keepTasks): bool {
            $locked = User::query()->whereKey($user->id)->lock('for no key update')->firstOrFail();
            if ($locked->bureaucracyCase()->exists()
                || BureaucracyPerson::query()->where('account_user_id', $locked->id)->exists()) {
                return false;
            }
            $locked->update([
                'onboarded_at' => null,
                'situation' => null,
                'is_eu' => null,
                'veedel' => null,
                'arrival_date' => null,
                'german_level' => null,
                'bureaucracy_path' => null,
                'profile_attributes' => null,
            ]);
            $locked->attributeChanges()->delete();
            if (! $keepTasks) {
                $locked->userTasks()->delete();
            }

            return true;
        });
    }
}
