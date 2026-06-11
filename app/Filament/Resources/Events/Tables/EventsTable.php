<?php

namespace App\Filament\Resources\Events\Tables;

use App\Models\Event;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class EventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('needs_review', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->searchable(),
                IconColumn::make('needs_review')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'hidden' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('relevance')
                    ->numeric(2)
                    ->sortable(),
                TextColumn::make('category')
                    ->searchable(),
                TextColumn::make('starts_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('recurrence')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('location_name')
                    ->searchable(),
                TextColumn::make('summary_en')
                    ->limit(60)
                    ->toggleable(),
                TextColumn::make('chips')
                    ->toggleable(),
                IconColumn::make('is_free')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source')
                    ->searchable(),
                TextColumn::make('quality_score')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('needs_review'),
            ])
            ->recordActions([
                Action::make('approve')
                    ->visible(fn (Event $record): bool => $record->needs_review)
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->action(fn (Event $record) => $record->update([
                        'needs_review' => false,
                        'verified_at' => now(),
                    ])),
                Action::make('hide')
                    ->visible(fn (Event $record): bool => $record->status !== 'hidden')
                    ->icon('heroicon-o-eye-slash')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(fn (Event $record) => $record->update([
                        'status' => 'hidden',
                        'needs_review' => false,
                    ])),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
