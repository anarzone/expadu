<?php

namespace App\Filament\Resources\WaitlistSignups;

use App\Admin\PeopleActions;
use App\Filament\Clusters\People\PeopleCluster;
use App\Filament\Resources\WaitlistSignups\Pages\ListWaitlistSignups;
use App\Models\WaitlistSignup;
use App\Security\DisposableEmail;
use App\Security\SecurityStats;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/** The city waitlist (People › Waitlist): read, and remove what isn't a person. */
class WaitlistSignupResource extends Resource
{
    protected static ?string $model = WaitlistSignup::class;

    protected static ?string $cluster = PeopleCluster::class;

    protected static ?string $slug = 'waitlist';

    protected static ?string $modelLabel = 'waitlist entry';

    protected static ?string $pluralModelLabel = 'Waitlist';

    protected static ?string $navigationLabel = 'Waitlist';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('email')->label('Email')->searchable()->weight('semibold'),
                TextColumn::make('city')->searchable()->placeholder('–'),
                TextColumn::make('source')->label('From')->placeholder('–'),
                TextColumn::make('created_at')->label('Signed up')->since()->sortable()
                    ->tooltip(fn (WaitlistSignup $record): string => $record->created_at?->format('j M Y, H:i') ?? ''),
                TextColumn::make('state')->label('State')->badge()
                    ->state(fn (WaitlistSignup $record): string => self::state($record))
                    ->color(fn (WaitlistSignup $record): string => $record->confirmed_at ? 'success' : 'gray'),
                TextColumn::make('flags')->label('Flags')->badge()->placeholder('–')
                    ->state(fn (WaitlistSignup $record): array => array_keys(self::flags($record)))
                    ->color(fn (string $state, WaitlistSignup $record): string => self::flags($record)[$state] ?? 'gray'),
            ])
            ->recordActions([
                Action::make('remove')->hiddenLabel()->tooltip('Remove')->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (WaitlistSignup $record): string => "Remove {$record->email}?")
                    ->modalDescription('The entry is deleted from the waitlist. This can’t be undone.')
                    ->modalSubmitActionLabel('Remove')
                    ->action(function (WaitlistSignup $record, PeopleActions $people): void {
                        $people->removeFromWaitlist($record);
                        Notification::make()->title('Removed from the waitlist.')->success()->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('remove')->label('Remove from the waitlist')->icon(Heroicon::OutlinedTrash)->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Remove these entries?')
                        ->modalDescription('They are deleted from the waitlist. This can’t be undone.')
                        ->modalSubmitActionLabel('Remove')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, PeopleActions $people): void {
                            $records->each(fn (WaitlistSignup $signup) => $people->removeFromWaitlist($signup));
                            Notification::make()->title($records->count().' removed from the waitlist.')->success()->send();
                        }),
                ]),
            ]);
    }

    /** Confirmed (and how fast), or waiting and when the clean-up takes it. */
    public static function state(WaitlistSignup $signup): string
    {
        $at = CarbonImmutable::parse($signup->created_at);
        if ($signup->confirmed_at !== null) {
            $seconds = (int) $at->diffInSeconds(CarbonImmutable::parse($signup->confirmed_at), true);

            return $seconds < SecurityStats::ScannerSeconds ? "Confirmed in {$seconds} s" : 'Confirmed';
        }

        return $at->lt(CarbonImmutable::parse((string) config('cleanup.waitlist.from')))
            ? 'Waiting · kept'
            : 'Waiting · goes '.$at->addDays((int) config('cleanup.waitlist.unconfirmed_days'))->format('j M');
    }

    /** @return array<string, string> flag => colour */
    public static function flags(WaitlistSignup $signup): array
    {
        $flags = [];
        if (str_starts_with(self::state($signup), 'Confirmed in')) {
            $flags['Scanner?'] = 'danger';
        }
        if (app(DisposableEmail::class)->isDisposable((string) $signup->email)) {
            $flags['Throwaway inbox'] = 'danger';
        }

        return $flags;
    }

    public static function getPages(): array
    {
        return ['index' => ListWaitlistSignups::route('/')];
    }
}
