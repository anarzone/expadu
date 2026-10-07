<?php

namespace App\Bureaucracy\People;

use App\Models\BureaucracyAccessGrant;
use App\Models\BureaucracyGuardianAuthority;
use App\Models\BureaucracyPerson;
use App\Models\User;
use App\Privacy\ProcessingConsentStore;
use Illuminate\Auth\Access\AuthorizationException;

final class PersonAccess
{
    public function allows(User $actor, BureaucracyPerson $person, AccessScope $scope): bool
    {
        return in_array($scope->value, $this->scopesFor($actor, $person), true);
    }

    /** @return list<string> */
    public function scopesFor(User $actor, BureaucracyPerson $person): array
    {
        $current = $this->current($actor, $person);
        if ($current === null) {
            return [];
        }
        if ($current->account_user_id === $actor->id) {
            return AccessScope::values();
        }

        return BureaucracyAccessGrant::query()->where('person_id', $current->id)->where('grantee_user_id', $actor->id)
            ->whereNull('revoked_at')->where('expires_at', '>', now()->utc())->get()
            ->filter(fn (BureaucracyAccessGrant $grant): bool => $this->validGrant($grant, $current))
            ->flatMap(fn ($grant) => array_intersect($grant->scopes, AccessScope::values()))->unique()->values()->all();
    }

    public function authorize(User $actor, BureaucracyPerson $person, AccessScope $scope): void
    {
        if (! $this->allows($actor, $person, $scope)) {
            throw new AuthorizationException;
        }
    }

    /** Bind transient operations to the exact authority, not merely to today's union of scopes. */
    public function authorityToken(User $actor, BureaucracyPerson $person): ?string
    {
        $current = $this->current($actor, $person);
        if ($current === null) {
            return null;
        }
        if ($current->account_user_id === $actor->id) {
            return ProcessingConsentStore::digest(['owner', $actor->id, $current->id]);
        }
        $grants = BureaucracyAccessGrant::query()->where('person_id', $current->id)->where('grantee_user_id', $actor->id)
            ->whereNull('revoked_at')->where('expires_at', '>', now()->utc())->orderBy('id')->get()
            ->filter(fn ($grant) => $this->validGrant($grant, $current))->map(fn ($grant) => [
                'id' => $grant->id, 'version' => $grant->version, 'scopes' => $grant->scopes,
                'authority_basis' => $grant->authority_basis, 'guardian_authority_id' => $grant->guardian_authority_id,
                'expires_at' => $grant->expires_at->toIso8601String(), 'notice_version' => $grant->notice_version,
            ])->values()->all();

        return $grants === [] ? null : ProcessingConsentStore::digest([$actor->id, $current->id, $grants, config('bureaucracy_family.guardian_policy_version')]);
    }

    public function canManage(User $actor, BureaucracyPerson $person): bool
    {
        $current = $this->current($actor, $person);

        return $current !== null && ($current->account_user_id === $actor->id
            || $this->guardianAuthority($current, $actor->id) !== null);
    }

    public function guardianAuthority(BureaucracyPerson $person, int $guardianId): ?BureaucracyGuardianAuthority
    {
        $version = config('bureaucracy_family.guardian_policy_version');
        if (! is_string($version) || $version === '' || $person->kind !== 'dependent' || $person->account_user_id !== null) {
            return null;
        }

        return BureaucracyGuardianAuthority::query()->where('person_id', $person->id)->where('guardian_user_id', $guardianId)
            ->where('status', 'approved')->where('policy_version', $version)->whereNotNull('reviewed_by')
            ->whereNotNull('reviewed_at')->whereNotNull('evidence_reference')->whereNull('revoked_at')
            ->where('expires_at', '>', now()->utc())->first();
    }

    private function current(User $actor, BureaucracyPerson $person): ?BureaucracyPerson
    {
        if (! User::query()->whereKey($actor->id)->whereNotNull('email_verified_at')->exists()) {
            return null;
        }

        return BureaucracyPerson::query()->whereKey($person->id)->where('record_status', 'active')->first();
    }

    private function validGrant(BureaucracyAccessGrant $grant, BureaucracyPerson $person): bool
    {
        if ($grant->notice_version !== config('bureaucracy_family.sharing_notice_version') || $grant->accepted_at->greaterThan(now())) {
            return false;
        }

        return match ($grant->authority_basis) {
            'adult_acceptance' => $person->account_user_id !== null && $person->account_user_id === $grant->grantor_user_id,
            'reviewed_guardian' => $grant->guardian_authority_id !== null && $grant->grantor_user_id !== null
                && $this->guardianAuthority($person, $grant->grantor_user_id)?->id === $grant->guardian_authority_id,
            default => false,
        };
    }
}
