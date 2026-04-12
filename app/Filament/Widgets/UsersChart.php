<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Widgets\ChartWidget;

class UsersChart extends ChartWidget
{
    protected ?string $heading = 'New Users (30 days)';

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $data = User::where('created_at', '>=', now()->subDays(30))
            ->selectRaw('date(created_at) as day, count(*) as count')
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('count', 'day');

        return [
            'datasets' => [
                [
                    'label' => 'New users',
                    'data' => $data->values()->all(),
                    'borderColor' => '#1A4CD4',
                    'backgroundColor' => 'rgba(26, 76, 212, 0.1)',
                    'fill' => true,
                ],
            ],
            'labels' => $data->keys()->map(fn ($d) => date('M d', strtotime($d)))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
