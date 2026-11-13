<?php

namespace App\Filament\Resources\WaitlistSignups\Pages;

use App\Filament\Resources\WaitlistSignups\WaitlistSignupResource;
use App\Models\WaitlistSignup;
use App\Security\SecurityStats;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListWaitlistSignups extends ListRecords
{
    protected static string $resource = WaitlistSignupResource::class;

    public function getSubheading(): string
    {
        return match ($this->activeTab) {
            'flagged' => 'Confirmed seconds after signing up: likely a mail scanner, not a person.',
            'confirmed' => 'On the list. They hear the day the app opens.',
            default => 'Never confirmed. Deleted after '.config('cleanup.waitlist.unconfirmed_days').' days; those from before '
                .CarbonImmutable::parse((string) config('cleanup.waitlist.from'))->format('j M').' are kept until you decide.',
        };
    }

    public function getTabs(): array
    {
        $scanner = fn (Builder $query) => $query->whereNotNull('confirmed_at')
            ->whereRaw('confirmed_at < created_at + make_interval(secs => ?)', [SecurityStats::ScannerSeconds]);

        return [
            'waiting' => Tab::make('Waiting')
                ->badge(WaitlistSignup::query()->whereNull('confirmed_at')->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('confirmed_at')),
            'flagged' => Tab::make('Flagged')
                ->badge($scanner(WaitlistSignup::query())->count())
                ->modifyQueryUsing($scanner),
            'confirmed' => Tab::make('Confirmed')
                ->badge(WaitlistSignup::query()->whereNotNull('confirmed_at')->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('confirmed_at')),
        ];
    }
}
