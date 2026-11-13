<?php

namespace App\Filament\Pages;

use App\Filament\Resources\SecurityEvents\SecurityEventResource;
use App\Filament\Widgets\Security\AlertBanner;
use App\Filament\Widgets\Security\NewestSignups;
use App\Filament\Widgets\Security\SecurityOverview;
use App\Filament\Widgets\Security\SecurityTrend;
use App\Filament\Widgets\Security\TurnedAwayAndAlerts;
use App\Security\SecurityStats;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * Sign-ups & security (the owner's pick A, 13 Nov 2026): the day's or week's
 * numbers, the last 14 days, the newest sign-ups with what's worth a look,
 * why posts were turned away and the watch's alerts. The full log is
 * SecurityEventResource (B), one click away.
 */
class SignupsSecurity extends Dashboard
{
    use HasFiltersForm;

    protected static string $routePath = 'security';

    protected static ?string $title = 'Sign-ups & security';

    protected static ?string $navigationLabel = 'Sign-ups & security';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 90;

    /** The sign-ups table needs the room. */
    protected Width|string|null $maxContentWidth = Width::Full;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            ToggleButtons::make('period')
                ->hiddenLabel()
                ->options(SecurityStats::Periods)
                ->default('7d')
                ->inline()
                ->grouped(),
        ]);
    }

    public function getWidgets(): array
    {
        return [AlertBanner::class, SecurityOverview::class, SecurityTrend::class, NewestSignups::class, TurnedAwayAndAlerts::class];
    }

    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 3, 'xl' => 4];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('log')
                ->label('Open the log')
                ->icon(Heroicon::OutlinedListBullet)
                ->color('gray')
                ->url(SecurityEventResource::getUrl()),
        ];
    }
}
