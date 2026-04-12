<?php

namespace App\Filament\Resources\Tasks\Schemas;

use App\Enums\DeadlineType;
use App\Enums\Urgency;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TaskForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required(),
                Textarea::make('description')
                    ->columnSpanFull(),
                TextInput::make('situation')
                    ->required(),
                TextInput::make('phase'),
                Select::make('deadline_type')
                    ->options(DeadlineType::class)
                    ->default('none')
                    ->required(),
                TextInput::make('deadline_days')
                    ->numeric(),
                Select::make('urgency')
                    ->options(Urgency::class)
                    ->default('medium')
                    ->required(),
                TextInput::make('links'),
                TextInput::make('documents_required'),
            ]);
    }
}
