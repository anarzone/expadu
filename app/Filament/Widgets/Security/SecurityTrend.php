<?php

namespace App\Filament\Widgets\Security;

use App\Security\SecurityStats;
use Filament\Widgets\ChartWidget;

/** Sign-ups against turned-away posts, the last 14 days: each on its own scale. */
class SecurityTrend extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Last 14 days';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '260px';

    protected ?string $pollingInterval = null;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $days = collect(app(SecurityStats::class)->days(14));

        return [
            'datasets' => [
                ['label' => 'Sign-ups', 'data' => $days->pluck('signups')->all(), 'backgroundColor' => '#2563eb', 'yAxisID' => 'y'],
                ['label' => 'Turned away', 'data' => $days->pluck('blocked')->all(), 'backgroundColor' => '#f97316', 'yAxisID' => 'y1'],
            ],
            'labels' => $days->map(fn (array $d): string => $d['day']->format('j M'))->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => ['position' => 'left', 'beginAtZero' => true, 'ticks' => ['precision' => 0], 'title' => ['display' => true, 'text' => 'Sign-ups']],
                'y1' => ['position' => 'right', 'beginAtZero' => true, 'ticks' => ['precision' => 0], 'grid' => ['drawOnChartArea' => false], 'title' => ['display' => true, 'text' => 'Turned away']],
            ],
        ];
    }
}
