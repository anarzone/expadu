<?php

namespace App\Bureaucracy\People;

use App\Bureaucracy\ReadModel\ReassessmentEvents;
use App\Models\BureaucracyAccessGrant;
use App\Models\BureaucracyExtractionCandidate;
use App\Models\BureaucracyInvitation;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyProcessingConsent;
use App\Models\BureaucracyWorkspace;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ManageDelegation
{
    public function __construct(private EnsureAccountHolder $holders, private PersonAccess $access, private ReassessmentEvents $refresh) {}

    /** @return array{invitation: BureaucracyInvitation, token: string} */
    public function invite(User $actor, BureaucracyWorkspace $workspace, string $email, array $scopes): array
    {
        $scopes = AccessScope::validate($scopes);
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['email' => 'Enter the intended recipient’s email address.']);
        }

        return DB::transaction(function () use ($actor, $workspace, $email, $scopes): array {
            $this->verifiedActor($actor);
            if (! BureaucracyWorkspace::query()->whereKey($workspace->id)->where('owner_user_id', $actor->id)->lockForUpdate()->exists()) {
                throw new AuthorizationException;
            }
            if (self::recipientHash($actor->email) === self::recipientHash($email)) {
                throw ValidationException::withMessages(['email' => 'Your own plan is already available to you.']);
            }
            $token = Str::random(64);
            $invitation = BureaucracyInvitation::query()->create([
                'workspace_id' => $workspace->id, 'inviter_user_id' => $actor->id,
                'recipient_hash' => self::recipientHash($email), 'token_hash' => hash('sha256', $token),
                'requested_scopes' => $scopes, 'notice_version' => config('bureaucracy_family.sharing_notice_version'),
                'expires_at' => now()->utc()->addHours(min(48, max(1, (int) config('bureaucracy_family.invitation_hours', 48)))),
            ]);

            return compact('invitation', 'token');
        });
    }

    public function accept(User $actor, string $token, array $scopes): BureaucracyPerson
    {
        $scopes = AccessScope::validate($scopes);

        return DB::transaction(function () use ($actor, $token, $scopes): BureaucracyPerson {
            $user = $this->verifiedActor($actor);
            $invitation = BureaucracyInvitation::query()->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if ($invitation === null || $invitation->accepted_at !== null || $invitation->revoked_at !== null
                || $invitation->expires_at->lessThanOrEqualTo(now())
                || $invitation->notice_version !== config('bureaucracy_family.sharing_notice_version')
                || ! hash_equals($invitation->recipient_hash, self::recipientHash($user->email))) {
                throw new AuthorizationException;
            }
            if (array_diff($scopes, $invitation->requested_scopes) !== []) {
                throw ValidationException::withMessages(['scopes' => 'Only access listed in this invitation may be accepted.']);
            }
            $person = $this->holders->person($user);
            $person = BureaucracyPerson::query()->whereKey($person->id)->lockForUpdate()->firstOrFail();
            if ($person->record_status !== 'active') {
                throw new AuthorizationException;
            }
            $this->holders->dossier($user);
            $person->workspaces()->syncWithoutDetaching([$invitation->workspace_id]);
            $person->grants()->where('grantee_user_id', $invitation->inviter_user_id)->whereNull('revoked_at')
                ->update(['revoked_at' => now()->utc()]);
            $caseId = $person->dossier()->value('id');
            BureaucracyProcessingConsent::query()->where('case_id', $caseId)->where('actor_id', $invitation->inviter_user_id)
                ->update(['withdrawn_at' => now()->utc(), 'state' => 'withdrawn', 'result' => null]);
            BureaucracyExtractionCandidate::query()->where('case_id', $caseId)->where('actor_id', $invitation->inviter_user_id)
                ->update(['state' => 'invalidated', 'value' => null, 'confirmation_token' => null]);
            BureaucracyAccessGrant::query()->create([
                'person_id' => $person->id, 'grantee_user_id' => $invitation->inviter_user_id,
                'grantor_user_id' => $user->id, 'invitation_id' => $invitation->id,
                'authority_basis' => 'adult_acceptance', 'scopes' => $scopes,
                'notice_version' => $invitation->notice_version, 'accepted_at' => now()->utc(),
                'expires_at' => now()->utc()->addDays(min(365, max(1, (int) config('bureaucracy_family.grant_days', 365)))),
            ]);
            $invitation->update(['accepted_person_id' => $person->id, 'accepted_at' => now()->utc()]);
            $person->increment('record_version');
            $this->refresh->accessChanged($person);

            return $person->fresh();
        });
    }

    public function inspect(User $actor, string $token): array
    {
        $user = User::query()->whereKey($actor->id)->whereNotNull('email_verified_at')->first();
        $invitation = BureaucracyInvitation::query()->where('token_hash', hash('sha256', $token))->first();
        if ($user === null || $invitation === null || $invitation->accepted_at !== null || $invitation->revoked_at !== null
            || $invitation->expires_at->lessThanOrEqualTo(now())
            || $invitation->notice_version !== config('bureaucracy_family.sharing_notice_version')
            || ! hash_equals($invitation->recipient_hash, self::recipientHash($user->email))) {
            throw new AuthorizationException;
        }

        return [
            'requested_scopes' => $invitation->requested_scopes,
            'requested_by' => User::query()->whereKey($invitation->inviter_user_id)->value('name'),
            'notice_version' => $invitation->notice_version,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'maximum_grant_days' => min(365, max(1, (int) config('bureaucracy_family.grant_days', 365))),
        ];
    }

    public function revoke(User $actor, BureaucracyAccessGrant $grant): void
    {
        DB::transaction(function () use ($actor, $grant): void {
            $this->verifiedActor($actor);
            $current = BureaucracyAccessGrant::query()->whereKey($grant->id)->firstOrFail();
            $person = BureaucracyPerson::query()->whereKey($current->person_id)->lockForUpdate()->firstOrFail();
            if ($current->grantee_user_id !== $actor->id && ! $this->access->canManage($actor, $person)) {
                throw new AuthorizationException;
            }
            $current = BureaucracyAccessGrant::query()->whereKey($current->id)->lockForUpdate()->firstOrFail();
            if ($current->revoked_at !== null) {
                return;
            }
            if ($current->authority_basis === 'reviewed_guardian') {
                // A guardian's own access follows their reviewed authority; a co-guardian must not strip it.
                throw ValidationException::withMessages(['grant' => 'Guardian access ends only when the guardian authority is revoked.']);
            }
            $current->update(['revoked_at' => now()->utc(), 'version' => $current->version + 1]);
            $person->increment('record_version');
            $this->refresh->accessChanged($person);
            BureaucracyProcessingConsent::query()->where('case_id', $person->dossier()->value('id'))
                ->where('actor_id', $current->grantee_user_id)
                ->update(['withdrawn_at' => now()->utc(), 'state' => 'withdrawn', 'result' => null]);
            BureaucracyExtractionCandidate::query()->where('case_id', $person->dossier()->value('id'))
                ->where('actor_id', $current->grantee_user_id)->update(['state' => 'invalidated', 'value' => null, 'confirmation_token' => null]);
        });
    }

    public function cancelInvitation(User $actor, BureaucracyInvitation $invitation): void
    {
        DB::transaction(function () use ($actor, $invitation): void {
            $this->verifiedActor($actor);
            $current = BureaucracyInvitation::query()->whereKey($invitation->id)->where('inviter_user_id', $actor->id)->lockForUpdate()->first();
            if ($current === null) {
                throw new AuthorizationException;
            }
            if ($current->accepted_at !== null) {
                throw ValidationException::withMessages(['invitation' => 'This invitation was accepted. Revoke the sharing permission to end access.']);
            }
            $current->update(['revoked_at' => now()->utc()]);
        });
    }

    private function verifiedActor(User $actor): User
    {
        $user = User::query()->whereKey($actor->id)->whereNotNull('email_verified_at')->lock('for no key update')->first();
        if ($user === null) {
            throw new AuthorizationException;
        }

        return $user;
    }

    public static function recipientHash(string $email): string
    {
        return hash_hmac('sha256', 'bureaucracy-invitation:'.Str::lower(trim($email)), (string) config('app.key'));
    }
}
