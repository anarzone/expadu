<?php

namespace App\Bureaucracy\ReadModel;

use App\Models\BureaucracyPerson;
use App\Models\User;

final class AccountHolderPlan
{
    public function __construct(private PlanReadModel $plans) {}

    /** Compatibility consumers are self-only. Missing identity is never another family member. */
    public function for(User $user): ?array
    {
        $person = BureaucracyPerson::query()->where('account_user_id', $user->id)->where('record_status', 'active')
            ->whereHas('dossier', fn ($query) => $query->where('status', 'active'))->first();
        if ($person === null || ! User::query()->whereKey($user->id)->whereNotNull('email_verified_at')->exists()) {
            return null;
        }

        return $this->plans->for($user, $person, $this->jurisdiction($user));
    }

    public function jurisdiction(User $user): string
    {
        $city = mb_strtolower(trim((string) $user->city));
        foreach (config('bureaucracy_catalogue.jurisdictions') as $key => $jurisdiction) {
            if (in_array($city, array_map(mb_strtolower(...), [$jurisdiction['city'], ...($jurisdiction['city_aliases'] ?? [])]), true)) {
                return $key;
            }
        }

        return 'outside_coverage';
    }
}
