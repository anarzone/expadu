<?php

namespace App\Personalisation;

use App\Models\User;

/**
 * The key the ledger and its aggregates sit under: a keyed hash of the
 * account id, never the id itself. Deleting the account deletes by it.
 */
final class Pseudonym
{
    public static function for(User|int $user): string
    {
        $id = $user instanceof User ? (int) $user->getKey() : $user;
        $key = (string) (config('personalisation.pseudonym_key') ?: config('app.key'));

        return hash_hmac('sha256', 'signals:'.$id, $key);
    }
}
