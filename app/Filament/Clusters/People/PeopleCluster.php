<?php

namespace App\Filament\Clusters\People;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;

/**
 * People (the owner's pick, 13 Nov 2026: A with B's queue as the first tab):
 * Needs a look, Users and Waitlist, as tabs along the top.
 */
class PeopleCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'People';

    protected static ?string $slug = 'people';

    protected static ?int $navigationSort = 80;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
}
