<?php

namespace App\Home;

use App\Bureaucracy\ReadModel\AccountHolderPlan;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class CurrentBureaucracyPlan
{
    public function __construct(private AccountHolderPlan $plans) {}

    /** Home snapshots are not authority to replay personal or legal guidance. */
    public function for(HomeContext $context): ?array
    {
        if ($context->bureaucracyPlan === null) {
            return null;
        }

        $user = User::query()->find($context->userId);
        if ($user === null) {
            return null;
        }

        try {
            // Reuse the canonical assessment, including current city, consent,
            // facts, source validity and time boundaries. Never cache approval.
            $plan = $this->plans->for($user);
        } catch (AuthorizationException) {
            return null;
        }

        return $plan !== null && ($context->bureaucracyPlan['person_id'] ?? null) === $plan['person_id']
            ? $plan : null;
    }
}
