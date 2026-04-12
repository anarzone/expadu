<?php

namespace App\Filament\Widgets;

use App\Models\Alert;
use Filament\Widgets\ChartWidget;

class AlertsChart extends ChartWidget
{
    protected ?string $heading = 'Alerts by Type (7 days)';

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $types = ['system', 'social', 'reminder'];
        $datasets = [];

        $colors = [
            'system' => '#DC2626',
            'social' => '#1A4CD4',
            'reminder' => '#F59E0B',
        ];

        foreach ($types as $type) {
            $data = Alert::where('created_at', '>=', now()->subDays(7))
                ->where('type', $type)
                ->selectRaw('date(created_at) as day, count(*) as count')
                ->groupBy('day')
                ->orderBy('day')
                ->pluck('count', 'day');

            $datasets[] = [
                'label' => ucfirst($type),
                'data' => $data->values()->all(),
                'borderColor' => $colors[$type],
            ];
        }

        $days = Alert::where('created_at', '>=', now()->subDays(7))
            ->selectRaw('distinct date(created_at) as day')
            ->orderBy('day')
            ->pluck('day')
            ->map(fn ($d) => date('M d', strtotime($d)))
            ->all();

        return [
            'datasets' => $datasets,
            'labels' => $days,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
