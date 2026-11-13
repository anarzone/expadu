<?php

namespace App\Filament\Widgets\Security;

use App\Security\SecurityStats;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/** Why posts were turned away in the period, the domains they used, and the latest alerts. */
class TurnedAwayAndAlerts extends Widget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.security.turned-away-and-alerts';

    protected function getViewData(): array
    {
        $stats = app(SecurityStats::class);
        $since = SecurityStats::since($this->pageFilters['period'] ?? null);
        $reasons = $stats->overview($since)['blocked_reasons'];
        $max = max([1, ...array_values($reasons)]);

        return [
            'reasons' => collect($reasons)->map(fn (int $n, string $reason): array => [
                'label' => SecurityStats::Reasons[$reason] ?? $reason, 'count' => $n, 'width' => (int) round($n / $max * 100),
            ])->values()->all(),
            'domains' => $stats->blockedDomains($since),
            'alerts' => $stats->alerts(5),
        ];
    }
}
