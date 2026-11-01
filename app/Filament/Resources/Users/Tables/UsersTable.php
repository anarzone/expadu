<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\Situation;
use App\Models\User;
use App\Profile\ResetLegacyJourney;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_admin')
                    ->label('Admin')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('situation')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('city')
                    ->toggleable(),
                TextColumn::make('social_provider')
                    ->label('Social')
                    ->badge()
                    ->toggleable(),
                IconColumn::make('email_verified_at')
                    ->label('Verified')
                    ->boolean()
                    ->getStateUsing(fn ($record) => $record->email_verified_at !== null)
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Joined')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('is_admin')
                    ->label('Admin'),
                SelectFilter::make('situation')
                    ->options(Situation::class),
                TernaryFilter::make('email_verified_at')
                    ->label('Verified')
                    ->nullable(),
            ])
            ->recordActions([
                EditAction::make(),
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
                            Notification::make()
                                ->title("{$record->email} has a Paperwork record. Nothing was reset.")
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title("{$record->email} reset")
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
