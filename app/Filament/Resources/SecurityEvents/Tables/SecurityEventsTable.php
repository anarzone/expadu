<?php

namespace App\Filament\Resources\SecurityEvents\Tables;

use App\Models\SecurityEvent;
use App\Security\SecurityLog;
use App\Security\SecurityStats;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;

class SecurityEventsTable
{
    /** What happened, in words and colour. */
    public const Types = [
        SecurityLog::Signup => ['Sign-up', 'primary'],
        SecurityLog::Login => ['Signed in', 'success'],
        SecurityLog::LoginFailed => ['Failed sign-in', 'danger'],
        SecurityLog::TwoFactorFailed => ['Wrong code', 'danger'],
        SecurityLog::Throttled => ['Refused', 'danger'],
        SecurityLog::Blocked => ['Turned away', 'warning'],
        SecurityLog::ResetRequested => ['Reset asked', 'gray'],
        SecurityLog::PasswordReset => ['Password reset', 'gray'],
        SecurityLog::EmailVerified => ['Verified', 'success'],
        SecurityLog::MailHeld => ['Mail held', 'gray'],
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('j M, H:i')->sortable(),
                TextColumn::make('type')->label('What')->badge()
                    ->formatStateUsing(fn (string $state): string => self::Types[$state][0] ?? $state)
                    ->color(fn (string $state): string => self::Types[$state][1] ?? 'gray'),
                TextColumn::make('channel')->label('Where')
                    ->formatStateUsing(fn (string $state): string => SecurityStats::Channels[$state] ?? $state),
                TextColumn::make('user.name')->label('Account')->placeholder('–')
                    ->description(fn (SecurityEvent $record): ?string => $record->user?->email)
                    ->searchable(['name', 'email'])->wrap(),
                TextColumn::make('email_domain')->label('Domain')->placeholder('–')->searchable(),
                TextColumn::make('ip')->label('IP')->placeholder('–')->searchable()->fontFamily('mono')->color('primary')
                    ->action(self::ipProfile()),
                TextColumn::make('user_agent')->label('Browser')->placeholder('–')
                    ->formatStateUsing(fn (string $state): string => SecurityStats::browser($state))
                    ->tooltip(fn (SecurityEvent $record): ?string => $record->user_agent)->wrap()->toggleable(),
                TextColumn::make('reason')->label('Why')->placeholder('–')
                    ->formatStateUsing(fn (string $state): string => SecurityStats::Reasons[$state] ?? str_replace(['_', ':'], [' ', ': '], $state))->wrap(),
            ])
            ->filters([
                SelectFilter::make('channel')->label('Where')->options(SecurityStats::Channels),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->poll('60s');
    }

    /** Everything the clicked IP did, and what it looks like (SecurityStats::ipProfile). */
    private static function ipProfile(): Action
    {
        return Action::make('ipProfile')
            ->slideOver()
            ->modalWidth('md')
            ->modalHeading(fn (SecurityEvent $record): string => (string) $record->ip)
            ->modalDescription('Everything this address did in the last 90 days.')
            ->modalContent(fn (SecurityEvent $record): View => view('filament.security.ip-profile', [
                'profile' => app(SecurityStats::class)->ipProfile((string) $record->ip),
                'types' => self::Types,
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->disabled(fn (SecurityEvent $record): bool => $record->ip === null);
    }
}
