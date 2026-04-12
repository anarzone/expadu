<?php

namespace App\Filament\Resources\Events\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class EventForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required(),
                TextInput::make('emoji'),
                TextInput::make('category'),
                Textarea::make('description')
                    ->columnSpanFull(),
                DateTimePicker::make('starts_at')
                    ->required(),
                DateTimePicker::make('ends_at'),
                TextInput::make('location_name'),
                TextInput::make('address'),
                TextInput::make('max_attendees')
                    ->numeric(),
                Toggle::make('is_free')
                    ->required(),
                TextInput::make('price')
                    ->numeric()
                    ->prefix('$'),
                Select::make('organiser_id')
                    ->relationship('organiser', 'name')
                    ->required(),
                TextInput::make('location'),
                TextInput::make('tags'),
                Toggle::make('is_expat_relevant')
                    ->required(),
                TextInput::make('source'),
                TextInput::make('source_url')
                    ->url(),
                TextInput::make('quality_score')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('price_text'),
            ]);
    }
}
