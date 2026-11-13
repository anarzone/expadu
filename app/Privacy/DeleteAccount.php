<?php

namespace App\Privacy;

use App\Models\User;
use App\Services\LocationDataRetention;

/**
 * Deletes an account and everything no foreign key cascades: the person's
 * own "Delete account" and the clean-up of accounts never verified
 * (accounts:prune-unverified) go the same way.
 */
final class DeleteAccount
{
    public function __construct(private readonly LocationDataRetention $retention, private readonly ForgetAccount $forget) {}

    /** @param  (callable(): void)|null  $alsoInLock  run inside the lock, before the row goes (the web request's sign-out) */
    public function delete(User $user, ?callable $alsoInLock = null): void
    {
        $this->retention->withUserLock($user->id, function () use ($user, $alsoInLock): void {
            $this->retention->purgeUser($user->id);
            $this->forget->forget($user);
            if ($alsoInLock !== null) {
                $alsoInLock();
            }
            $user->delete();
        });
    }
}
