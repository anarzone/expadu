<?php

namespace App\Bureaucracy;

use App\Bureaucracy\ReadModel\AccountHolderPlan;
use App\Bureaucracy\ReadModel\PlanAttention;
use App\Models\User;

/** The badge counts distinct processes needing attention, not hypothetical routes. */
class OpenTaskCount
{
    public function __construct(private AccountHolderPlan $plans, private PlanAttention $attention) {}

    public function forUser(User $user): int
    {
        return $this->attention->count($this->plans->for($user));
    }
}
