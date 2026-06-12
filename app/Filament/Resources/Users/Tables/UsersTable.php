<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\Situation;
use App\Models\User;
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
                // Testing tool: replay the full journey (onboarding → path →
                // teasers → life events) on this account without registering
                // a fresh one. Mirrors `php artisan user:reset-journey`.
                Action::make('resetJourney')
                    ->label('Reset journey')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Reset this user to pre-onboarding?')
                    ->modalDescription('Wipes onboarding answers, profile attributes + change log, bureaucracy path and all task progress. The user restarts at onboarding on their next visit.')
                    ->action(function (User $record): void {
                        $record->update([
                            'onboarded_at' => null,
                            'situation' => null,
                            'is_eu' => null,
                            'veedel' => null,
                            'arrival_date' => null,
                            'german_level' => null,
                            'bureaucracy_path' => null,
                            'profile_attributes' => null,
                        ]);
                        $record->attributeChanges()->delete();
                        $record->userTasks()->delete();

                        Notification::make()
                            ->title("{$record->email} reset — they restart at onboarding")
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
