<?php

namespace App\Filament\Resources\Alerts\Schemas;

use App\Enums\AlertType;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AlertForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->required(),
                Select::make('type')
                    ->options(AlertType::class)
                    ->required(),
                TextInput::make('title')
                    ->required(),
                Textarea::make('body')
                    ->columnSpanFull(),
                TextInput::make('deep_link'),
                DateTimePicker::make('read_at'),
                DateTimePicker::make('dismissed_at'),
                TextInput::make('subtype'),
            ]);
    }
}
