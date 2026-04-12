<?php

namespace App\Filament\Resources\CityNews\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CityNewsForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required(),
                Textarea::make('summary')
                    ->columnSpanFull(),
                TextInput::make('source')
                    ->required(),
                TextInput::make('source_url')
                    ->url()
                    ->required(),
                TextInput::make('category')
                    ->required()
                    ->default('general'),
                TextInput::make('relevance')
                    ->required()
                    ->default('all'),
                DateTimePicker::make('published_at'),
                DateTimePicker::make('expires_at'),
                TextInput::make('affected_lines'),
                TextInput::make('severity'),
            ]);
    }
}
