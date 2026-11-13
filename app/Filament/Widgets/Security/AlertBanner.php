<?php

namespace App\Filament\Widgets\Security;

use App\Filament\Resources\SecurityEvents\SecurityEventResource;
use App\Security\SecurityStats;
use Filament\Widgets\Widget;

/** Today's alert from the watch, over everything else, while there is one. */
class AlertBanner extends Widget
{
    protected static bool $isDiscovered = false;

    protected string $view = 'filament.security.alert-banner';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return app(SecurityStats::class)->alertToday() !== null;
    }

    protected function getViewData(): array
    {
        return ['alert' => app(SecurityStats::class)->alertToday(), 'log' => SecurityEventResource::getUrl()];
    }
}
