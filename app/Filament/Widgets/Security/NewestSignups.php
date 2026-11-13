<?php

namespace App\Filament\Widgets\Security;

use App\Filament\Resources\SecurityEvents\SecurityEventResource;
use App\Filament\Resources\Users\UserResource;
use App\Security\SecurityStats;
use Filament\Widgets\Widget;

/** The newest accounts and waitlist sign-ups together, flagged where worth a look. */
class NewestSignups extends Widget
{
    protected static bool $isDiscovered = false;

    protected string $view = 'filament.security.newest-signups';

    protected int|string|array $columnSpan = ['default' => 1, 'lg' => 2, 'xl' => 3];

    protected function getViewData(): array
    {
        return [
            'rows' => collect(app(SecurityStats::class)->newestSignups(8))->map(fn (array $row): array => [
                ...$row, 'url' => $row['user_id'] !== null ? UserResource::getUrl('edit', ['record' => $row['user_id']]) : null,
            ])->all(),
            'log' => SecurityEventResource::getUrl(),
        ];
    }
}
