<?php

namespace App\Filament\Widgets;

use App\Models\Alert;
use App\Models\CityNews;
use App\Models\Event;
use App\Models\Spot;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Total Users', User::count())
                ->description(User::where('created_at', '>=', now()->subDays(7))->count().' new this week')
                ->color('primary')
                ->icon('heroicon-o-users'),

            Stat::make('Total Spots', Spot::count())
                ->description(Spot::where('source', 'manual')->count().' curated')
                ->color('success')
                ->icon('heroicon-o-map-pin'),

            Stat::make('Events', Event::where('starts_at', '>', now())->count())
                ->description('Upcoming')
                ->color('warning')
                ->icon('heroicon-o-calendar'),

            Stat::make('Active Disruptions', CityNews::where('category', 'transit')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count())
                ->description(CityNews::where('severity', 'major')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count().' major')
                ->color('danger')
                ->icon('heroicon-o-exclamation-triangle'),

            Stat::make('Alerts Sent (7d)', Alert::where('created_at', '>=', now()->subDays(7))->count())
                ->description(Alert::where('created_at', '>=', now()->subDays(7))->whereNotNull('read_at')->count().' read')
                ->color('info')
                ->icon('heroicon-o-bell'),

            Stat::make('Onboarded', User::whereNotNull('onboarded_at')->count().'/'.User::count())
                ->description('Users completed onboarding')
                ->icon('heroicon-o-check-circle'),
        ];
    }
}
