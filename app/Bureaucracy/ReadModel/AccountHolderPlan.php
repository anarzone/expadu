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
        // Badge, Today tiles, the feed and Composer all ask for the same plan on
        // one page load. Within a read-only request nothing can change it, so
        // assess once. Writes and console commands always recompute.
        // The memo lives on the request object, so it can never outlive it.
        // Only inside a routed, read-only HTTP request; commands and writes recompute.
        if (! app()->bound('request') || request()->route() === null || ! request()->isMethodSafe()) {
            return $this->assess($user);
        }
        $key = 'bureaucracy.account_plan.'.$user->id;
        if (! request()->attributes->has($key)) {
            request()->attributes->set($key, $this->assess($user));
        }

        return request()->attributes->get($key);
    }

    private function assess(User $user): ?array
    {
        $person = $this->person($user);

        return $person === null ? null : $this->plans->for($user, $person, $this->jurisdiction($user));
    }

    /**
     * The account holder's own person, while their email is verified and their dossier is
     * active; never a family member's. Every read of "your own Paperwork" starts here.
     */
    public function person(User $user): ?BureaucracyPerson
    {
        $person = BureaucracyPerson::query()->where('account_user_id', $user->id)->where('record_status', 'active')
            ->whereHas('dossier', fn ($query) => $query->where('status', 'active'))->first();
        if ($person === null || ! User::query()->whereKey($user->id)->whereNotNull('email_verified_at')->exists()) {
            return null;
        }

        return $person;
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
