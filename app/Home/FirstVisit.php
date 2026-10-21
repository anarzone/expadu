<?php

namespace App\Home;

use App\Bureaucracy\ReadModel\AccountHolderPlan;
use App\Models\ComposerWorkspace;
use App\Models\EventBookmark;
use App\Models\SpotFeedback;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Whether Today greets someone new (the mock's first visit): nothing planned
 * on any day, nothing saved, no Composer conversation and no Paperwork
 * record yet. The welcome offers the three ways in; it never shows again
 * once any of those exists.
 */
final class FirstVisit
{
    public function __construct(private AccountHolderPlan $plans) {}

    public function for(User $user): bool
    {
        $state = ComposerWorkspace::query()->where('user_id', $user->id)->first()?->state ?? [];
        $planned = collect($state['plans'] ?? [])->contains(fn (mixed $day) => ! empty($day['slots'] ?? []));
        $talked = collect($state['conversations'] ?? [])->contains(fn (mixed $conversation) => ! empty($conversation['messages'] ?? []));

        if ($planned || $talked
            || EventBookmark::query()->where('user_id', $user->id)->exists()
            || SpotFeedback::query()->where('user_id', $user->id)->where('state', 'saved')->exists()) {
            return false;
        }

        try {
            return $this->plans->for($user) === null;
        } catch (AuthorizationException) {
            return true;
        }
    }
}
