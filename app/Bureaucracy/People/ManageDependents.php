<?php

namespace App\Bureaucracy\People;

use App\Bureaucracy\ReadModel\ReassessmentEvents;
use App\Models\BureaucracyAccessGrant;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyExtractionCandidate;
use App\Models\BureaucracyGuardianAuthority;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyProcessingConsent;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ManageDependents
{
    public function __construct(private EnsureAccountHolder $holders, private ReassessmentEvents $refresh) {}

    public function request(User $actor, string $label): BureaucracyGuardianAuthority
    {
        if (trim($label) === '' || mb_strlen($label) > 100) {
            throw ValidationException::withMessages(['label' => 'Use a short name for this family member.']);
        }

        return DB::transaction(function () use ($actor, $label): BureaucracyGuardianAuthority {
            $user = User::query()->whereKey($actor->id)->whereNotNull('email_verified_at')->lockForUpdate()->first();
            if ($user === null) {
                throw new AuthorizationException;
            }
            $holder = $this->holders->person($user);
            $person = BureaucracyPerson::query()->create([
                'workspace_id' => $holder->workspace_id, 'kind' => 'dependent', 'display_label' => trim($label),
            ]);
            $person->workspaces()->attach($holder->workspace_id);
            BureaucracyCase::query()->create(['person_id' => $person->id, 'user_id' => null]);

            return BureaucracyGuardianAuthority::query()->create(['person_id' => $person->id, 'guardian_user_id' => $user->id]);
        });
    }

    public function review(User $reviewer, BureaucracyGuardianAuthority $authority, string $policyVersion, string $evidenceReference, DateTimeInterface $expires): void
    {
        DB::transaction(function () use ($reviewer, $authority, $policyVersion, $evidenceReference, $expires): void {
            $staff = User::query()->whereKey($reviewer->id)->where('is_admin', true)->whereNotNull('email_verified_at')->lockForUpdate()->first();
            $current = BureaucracyGuardianAuthority::query()->whereKey($authority->id)->lockForUpdate()->firstOrFail();
            if ($staff === null || $current->guardian_user_id === $staff->id) {
                throw new AuthorizationException;
            }
            $expiry = CarbonImmutable::instance($expires)->utc();
            if ($policyVersion === '' || $policyVersion !== config('bureaucracy_family.guardian_policy_version')
                || trim($evidenceReference) === '' || mb_strlen($evidenceReference) > 500
                || $expiry->lessThanOrEqualTo(now()) || $expiry->greaterThan(now()->addYear())
                || $current->status !== 'pending' || $current->revoked_at !== null) {
                throw ValidationException::withMessages(['authority' => 'A current reviewed procedure and verified, time-limited authority record are required.']);
            }
            $person = BureaucracyPerson::query()->whereKey($current->person_id)->lockForUpdate()->firstOrFail();
            if ($person->account_user_id !== null || $person->kind !== 'dependent' || $person->record_status !== 'active') {
                throw new AuthorizationException;
            }
            $current->update([
                'status' => 'approved', 'policy_version' => $policyVersion, 'evidence_reference' => $evidenceReference,
                'reviewed_by' => $staff->id, 'reviewed_at' => now()->utc(), 'expires_at' => $expiry,
            ]);
            BureaucracyAccessGrant::query()->create([
                'person_id' => $person->id, 'grantee_user_id' => $current->guardian_user_id, 'grantor_user_id' => $current->guardian_user_id,
                'guardian_authority_id' => $current->id, 'authority_basis' => 'reviewed_guardian',
                'scopes' => AccessScope::values(), 'notice_version' => config('bureaucracy_family.sharing_notice_version'),
                'accepted_at' => now()->utc(), 'expires_at' => $expiry,
            ]);
            $person->increment('record_version');
            $this->refresh->accessChanged($person);
        });
    }

    public function revoke(User $actor, BureaucracyGuardianAuthority $authority): void
    {
        DB::transaction(function () use ($actor, $authority): void {
            $user = User::query()->whereKey($actor->id)->whereNotNull('email_verified_at')->lockForUpdate()->first();
            $current = BureaucracyGuardianAuthority::query()->whereKey($authority->id)->lockForUpdate()->firstOrFail();
            if ($user === null || ($current->guardian_user_id !== $user->id && ! $user->is_admin)) {
                throw new AuthorizationException;
            }
            if ($current->revoked_at !== null) {
                return;
            }
            $person = BureaucracyPerson::query()->whereKey($current->person_id)->lockForUpdate()->firstOrFail();
            $current->update(['status' => 'revoked', 'revoked_at' => now()->utc()]);
            $person->grants()->where('guardian_authority_id', $current->id)->update(['revoked_at' => now()->utc()]);
            $person->increment('record_version');
            $this->refresh->accessChanged($person);
            BureaucracyProcessingConsent::query()->where('case_id', $person->dossier()->value('id'))
                ->update(['withdrawn_at' => now()->utc(), 'state' => 'withdrawn', 'result' => null]);
            BureaucracyExtractionCandidate::query()->where('case_id', $person->dossier()->value('id'))
                ->update(['state' => 'invalidated', 'value' => null, 'confirmation_token' => null]);
        });
    }
}
