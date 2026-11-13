<?php

namespace App\Filament\Resources\Users\Tables;

use App\Admin\PeopleActions;
use App\Enums\Situation;
use App\Filament\Resources\Users\UserResource;
use App\Models\ComposerTrace;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Profile\ResetLegacyJourney;
use App\Security\SecurityLog;
use App\Security\SecurityStats;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Users (the owner's pick A): who, when, from where, verified, two-step,
 * last sign-in and flags; a row opens the person's sheet with their recent
 * sign-ins and what can be done: suspend (or lift it), resend verification,
 * edit the profile, delete the account.
 */
class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->select('users.*')->addSelect([
                'signup_channel' => SecurityEvent::query()->select('channel')->whereColumn('user_id', 'users.id')
                    ->where('type', SecurityLog::Signup)->limit(1),
                'last_login_at' => SecurityEvent::query()->selectRaw('max(created_at)')->whereColumn('user_id', 'users.id')
                    ->where('type', SecurityLog::Login),
            ]))
            ->columns([
                TextColumn::make('name')->label('Person')
                    ->description(fn (User $record): string => (string) $record->email)
                    ->searchable(['name', 'email'])->sortable(),
                TextColumn::make('created_at')->label('Joined')->since()->sortable()
                    ->tooltip(fn (User $record): string => $record->created_at?->format('j M Y, H:i') ?? ''),
                TextColumn::make('from')->label('From')->badge()->color('gray')
                    ->state(fn (User $record): string => self::from($record)),
                TextColumn::make('email_state')->label('Email')->badge()
                    ->state(fn (User $record): string => $record->email_verified_at ? 'Verified' : 'Not verified')
                    ->color(fn (string $state): string => $state === 'Verified' ? 'success' : 'gray'),
                TextColumn::make('two_step')->label('Two-step')->badge()
                    ->state(fn (User $record): string => $record->hasEnabledTwoFactorAuthentication() ? 'On' : 'Off')
                    ->color(fn (string $state): string => $state === 'On' ? 'success' : 'gray'),
                TextColumn::make('last_login_at')->label('Last sign-in')->placeholder('Never')
                    ->state(fn (User $record): ?CarbonImmutable => $record->getAttribute('last_login_at') ? CarbonImmutable::parse($record->getAttribute('last_login_at')) : null)
                    ->since(),
                TextColumn::make('flags')->label('Flags')->badge()->placeholder('–')
                    ->state(fn (User $record): array => array_keys(self::flags($record)))
                    ->color(fn (string $state, User $record): string => self::flags($record)[$state] ?? 'gray'),
                TextColumn::make('situation')->badge()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('city')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('is_admin')->label('Admin'),
                TernaryFilter::make('suspended_at')->label('Suspended')->nullable(),
                SelectFilter::make('situation')->options(Situation::class),
                TernaryFilter::make('email_verified_at')->label('Verified')->nullable(),
            ])
            // A row opens the person's sheet, not the edit form.
            ->recordUrl(null)
            ->recordAction('sheet')
            ->recordActions([
                self::sheet(),
                ActionGroup::make([
                    EditAction::make()->label('Edit profile'),
                    // Testing tool: replay a legacy profile's journey without
                    // registering a fresh account. Same reset, and the same refusal
                    // for an account with a Paperwork record, as `user:reset-journey`.
                    Action::make('resetJourney')
                        ->label('Reset journey')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Reset this user to pre-onboarding?')
                        ->modalDescription('Wipes onboarding answers, profile attributes + change log, bureaucracy path and all task progress. An account with a Paperwork record is not reset: use its correction or erasure flow.')
                        ->action(function (User $record, ResetLegacyJourney $resetJourney): void {
                            if (! $resetJourney->execute($record)) {
                                Notification::make()->title("{$record->email} has a Paperwork record. Nothing was reset.")->danger()->send();

                                return;
                            }

                            Notification::make()->title("{$record->email} reset")->success()->send();
                        }),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('deleteAccounts')
                        ->label('Delete accounts')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Delete these accounts?')
                        ->modalDescription('Each account and everything in it is deleted, as a person deleting their own would. Admin accounts are skipped. This can’t be undone.')
                        ->modalSubmitActionLabel('Delete for good')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, PeopleActions $people): void {
                            $deleted = $records->filter(fn (User $user): bool => $people->deleteAccount($user))->count();
                            Notification::make()->title("{$deleted} account".($deleted === 1 ? '' : 's').' deleted.')->success()->send();
                        }),
                ]),
            ]);
    }

    /** Where the account started: Google or Apple sign-in, the iPhone app, or the website. */
    public static function from(User $user): string
    {
        return match (true) {
            filled($user->apple_id) => 'Apple',
            filled($user->social_provider) => ucfirst((string) $user->social_provider),
            default => SecurityStats::Channels[(string) $user->getAttribute('signup_channel')] ?? 'Website',
        };
    }

    /** @return array<string, string> flag => colour */
    public static function flags(User $user): array
    {
        $flags = [];
        if ($user->is_admin) {
            $flags['Admin'] = 'info';
        }
        if ($user->isSuspended()) {
            $flags['Suspended'] = 'danger';
        }
        if ($user->email_verified_at === null && $user->created_at?->lt(now()->subDay())) {
            $flags['Unverified '.(int) $user->created_at->diffInDays(now()).'d'] = 'warning';
        }

        return $flags;
    }

    /** The person's sheet, and what can be done from it. */
    private static function sheet(): Action
    {
        return Action::make('sheet')
            ->hiddenLabel()->icon(Heroicon::OutlinedIdentification)->tooltip('Open')
            ->slideOver()->modalWidth('md')
            ->modalHeading(fn (User $record): string => (string) $record->name)
            ->modalDescription(fn (User $record): string => (string) $record->email)
            ->modalContent(fn (User $record): View => view('filament.people.person-sheet', [
                'user' => $record,
                'from' => self::from($record),
                'history' => SecurityEvent::query()->where('user_id', $record->id)
                    ->whereIn('type', [SecurityLog::Signup, SecurityLog::Login, SecurityLog::LoginFailed, SecurityLog::AdminAction])
                    ->latest('id')->limit(5)->get(),
                'composer' => ComposerTrace::query()->where('user_id', $record->id)->where('created_at', '>=', now()->subDays(7))->count(),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->extraModalFooterActions([
                Action::make('suspend')->label('Suspend')->icon(Heroicon::OutlinedNoSymbol)->color('gray')
                    ->visible(fn (User $record): bool => ! $record->is_admin && ! $record->isSuspended())
                    ->action(function (User $record, PeopleActions $people): void {
                        $people->suspend($record);
                        Notification::make()->title("{$record->name} is suspended and signed out everywhere.")->success()->send();
                    })->cancelParentActions(),
                Action::make('unsuspend')->label('Lift suspension')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
                    ->visible(fn (User $record): bool => $record->isSuspended())
                    ->action(function (User $record, PeopleActions $people): void {
                        $people->unsuspend($record);
                        Notification::make()->title("{$record->name} can sign in again.")->success()->send();
                    })->cancelParentActions(),
                Action::make('resendVerification')->label('Resend verification')->icon(Heroicon::OutlinedEnvelope)->color('gray')
                    ->visible(fn (User $record): bool => $record->email_verified_at === null)
                    ->action(function (User $record): void {
                        $record->sendEmailVerificationNotification();
                        Notification::make()->title('Verification email sent.')->success()->send();
                    }),
                Action::make('editProfile')->label('Edit profile')->icon(Heroicon::OutlinedPencilSquare)->color('gray')
                    ->url(fn (User $record): string => UserResource::getUrl('edit', ['record' => $record])),
                Action::make('deleteAccount')->label('Delete account')->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->visible(fn (User $record): bool => ! $record->is_admin)
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record): string => "Delete {$record->name}’s account?")
                    ->modalDescription('Everything in it goes: profile, Paperwork, Composer, places, trips. This can’t be undone.')
                    ->modalSubmitActionLabel('Delete for good')
                    ->action(function (User $record, PeopleActions $people): void {
                        $name = $record->name;
                        if ($people->deleteAccount($record)) {
                            Notification::make()->title("{$name}’s account is deleted.")->success()->send();
                        }
                    })->cancelParentActions(),
            ]);
    }
}
