<?php

namespace App\Bureaucracy\People;

use App\Bureaucracy\Ai\WithdrawPersonProcessing;
use App\Models\BureaucracyPerson;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Stop and resume Paperwork's processing of a record (GDPR Art. 18, EXP-180;
 * owner, 21 Oct 2026). Only the record's own holder may: not someone it's
 * shared with, not a manager. Stopping also withdraws any AI processing
 * (WithdrawPersonProcessing). While stopped the record is kept and its
 * holder still sees, downloads or deletes it, and nothing else uses it.
 */
final class RestrictPersonProcessing
{
    public function __construct(private WithdrawPersonProcessing $withdraw) {}

    public function stopped(User $actor, BureaucracyPerson $person): bool
    {
        return $this->holderRecord($actor, $person)->processing_stopped_at !== null;
    }

    public function stop(User $actor, BureaucracyPerson $person): void
    {
        $this->holderRecord($actor, $person);
        // Withdraw first, while the record is still processed as usual: it
        // runs under the holder's own access.
        $this->withdraw->execute($actor, $person);

        DB::transaction(function () use ($actor, $person): void {
            $current = $this->holderRecord($actor, $person, lock: true);
            if ($current->processing_stopped_at === null) {
                $current->forceFill(['processing_stopped_at' => now()->utc()])->save();
            }
        });
    }

    public function resume(User $actor, BureaucracyPerson $person): void
    {
        DB::transaction(function () use ($actor, $person): void {
            $current = $this->holderRecord($actor, $person, lock: true);
            if ($current->processing_stopped_at !== null) {
                $current->forceFill(['processing_stopped_at' => null])->save();
            }
        });
    }

    /** The active record, when the actor is its own verified holder. */
    private function holderRecord(User $actor, BureaucracyPerson $person, bool $lock = false): BureaucracyPerson
    {
        $query = BureaucracyPerson::query()->whereKey($person->id)->where('record_status', 'active')
            ->where('account_user_id', $actor->id);
        $current = ($lock ? $query->lockForUpdate() : $query)->first();

        if ($current === null || ! $actor->hasVerifiedEmail()) {
            throw new AuthorizationException;
        }

        return $current;
    }
}
