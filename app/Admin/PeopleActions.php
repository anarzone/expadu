<?php

namespace App\Admin;

use App\Models\User;
use App\Models\WaitlistSignup;
use App\Privacy\DeleteAccount;
use App\Security\SecurityLog;
use Illuminate\Support\Facades\DB;

/**
 * What the admin can do to an account or a waitlist entry from the People
 * pages. Each step is in the security log as an admin action, so there's a
 * record of who was suspended, kept or deleted, and when.
 */
final class PeopleActions
{
    public function __construct(private readonly DeleteAccount $deleteAccount, private readonly SecurityLog $log) {}

    /** Signs the account out everywhere and keeps it out until lifted; never an admin. */
    public function suspend(User $user): bool
    {
        if ($user->is_admin || $user->isSuspended()) {
            return false;
        }

        // All or nothing: never suspended with its iPhone tokens still working.
        DB::transaction(function () use ($user): void {
            $user->forceFill(['suspended_at' => now()])->save();
            // The iPhone's tokens go now; web sessions end on their next request (EnsureAccountIsActive).
            $user->tokens()->delete();
        });
        $this->log->record(SecurityLog::AdminAction, $user, reason: 'suspend', channel: 'admin');

        return true;
    }

    public function unsuspend(User $user): void
    {
        $user->forceFill(['suspended_at' => null])->save();
        $this->log->record(SecurityLog::AdminAction, $user, reason: 'unsuspend', channel: 'admin');
    }

    /** Off the "Needs a look" queue. */
    public function keep(User|WaitlistSignup $subject): void
    {
        $subject->forceFill(['reviewed_at' => now()])->save();
        $this->log->record(SecurityLog::AdminAction, $subject instanceof User ? $subject : null,
            email: $subject instanceof WaitlistSignup ? $subject->email : null, reason: 'keep', channel: 'admin');
    }

    public function unkeep(User|WaitlistSignup $subject): void
    {
        $subject->forceFill(['reviewed_at' => null])->save();
    }

    /** The whole account, as a person deleting their own would; never an admin. */
    public function deleteAccount(User $user): bool
    {
        if ($user->is_admin) {
            return false;
        }

        $this->log->record(SecurityLog::AdminAction, $user, reason: 'delete_account', channel: 'admin');
        $this->deleteAccount->delete($user);

        return true;
    }

    public function removeFromWaitlist(WaitlistSignup $signup): void
    {
        $this->log->record(SecurityLog::AdminAction, email: $signup->email, reason: 'remove_waitlist', channel: 'admin');
        $signup->delete();
    }
}
