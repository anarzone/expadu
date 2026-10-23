<?php

namespace App\Places;

use App\Models\User;
use App\Models\UserPlace;

/**
 * Your Home or Work, set to the place you picked, with its point: it fills the one you
 * have (an older account may hold an empty one) or makes it. Travel's address line
 * and Composer's set_my_place both set it here.
 */
final class SetHomeOrWork
{
    /** @param  array{name: string, address?: ?string, lat: float, lng: float}  $place */
    public function execute(User $user, string $category, array $place): UserPlace
    {
        $row = $user->places()->firstOrNew(['category' => $category]);
        $row->fill([
            ...$place,
            'emoji' => $row->emoji ?: ($category === 'home' ? '🏠' : '💼'),
        ]);
        $row->sort_order ??= $user->places()->count();
        $row->save();

        return $row;
    }
}
