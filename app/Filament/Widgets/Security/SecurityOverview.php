<?php

namespace App\Filament\Widgets\Security;

use App\Security\SecurityStats;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** The period's numbers, and today's email budget. */
class SecurityOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '60s';

    protected function getColumns(): int|array|null
    {
        return 5;
    }

    protected function getStats(): array
    {
        $stats = app(SecurityStats::class);
        $o = $stats->overview(SecurityStats::since($this->pageFilters['period'] ?? null));
        $mail = $stats->emailsToday();
        $names = ['web' => 'Website', 'app' => 'iPhone', 'google' => 'Google', 'apple' => 'Apple', 'admin' => 'Admin'];
        $topReasons = array_slice($o['blocked_reasons'], 0, 2, true);

        $kinds = collect($mail['kinds'])->map(fn (array $k, string $kind): string => ucfirst($kind).' '.$k['sent'].($k['share'] !== null ? '/'.$k['share'] : ''));

        return [
            Stat::make('New accounts', number_format($o['accounts']))
                ->description(collect($o['account_channels'])->map(fn (int $n, string $c): string => ($names[$c] ?? ucfirst($c)).' '.$n)->implode(' · ') ?: '–')
                ->icon(Heroicon::OutlinedUserPlus)->color('primary'),
            Stat::make('Waitlist sign-ups', number_format($o['waitlist']))
                ->description($o['waitlist_confirmed'].' confirmed')
                ->icon(Heroicon::OutlinedInboxArrowDown)->color('info'),
            Stat::make('Turned away', number_format($o['blocked']))
                ->description(collect($topReasons)->map(fn (int $n, string $r): string => (SecurityStats::Reasons[$r] ?? $r).' '.$n)->implode(' · ') ?: 'Nothing')
                ->icon(Heroicon::OutlinedNoSymbol)->color($o['blocked'] > 0 ? 'warning' : 'gray'),
            Stat::make('Failed sign-ins', number_format($o['failed']))
                ->description($o['throttled'].' refused by the limit')
                ->icon(Heroicon::OutlinedKey)->color($o['failed'] > 0 ? 'danger' : 'gray'),
            Stat::make('Emails today', $mail['total'].' of '.$mail['daily'])
                ->description($kinds->implode(' · '))
                ->icon(Heroicon::OutlinedEnvelope)
                ->color($mail['total'] >= 0.8 * $mail['daily'] ? 'danger' : 'gray'),
        ];
    }
}
