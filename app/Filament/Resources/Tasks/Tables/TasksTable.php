<?php

namespace App\Filament\Resources\Tasks\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class TasksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('phase')
                    ->searchable(),
                TextColumn::make('deadline_type')
                    ->badge()
                    ->searchable(),
                TextColumn::make('deadline_days')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('urgency')
                    ->badge()
                    ->searchable(),
                TextColumn::make('key')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('verified_at')
                    ->date()
                    ->sortable()
                    ->placeholder('unverified'),
                TextColumn::make('outdated_reports')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state): string => $state >= 3 ? 'danger' : ($state > 0 ? 'warning' : 'gray')),
                IconColumn::make('is_published')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('verified')
                    ->nullable()
                    ->attribute('verified_at')
                    ->trueLabel('Verified')
                    ->falseLabel('Unverified')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('verified_at'),
                        false: fn ($query) => $query->whereNull('verified_at'),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
