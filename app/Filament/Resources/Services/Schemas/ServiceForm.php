<?php

namespace App\Filament\Resources\Services\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('category')
                    ->required(),
                TextInput::make('subcategory'),
                TextInput::make('emoji'),
                Textarea::make('description')
                    ->columnSpanFull(),
                TextInput::make('address'),
                TextInput::make('lat')
                    ->numeric(),
                TextInput::make('lng')
                    ->numeric(),
                TextInput::make('phone')
                    ->tel(),
                TextInput::make('website')
                    ->url(),
                TextInput::make('languages'),
                TextInput::make('insurance_accepted'),
                TextInput::make('opening_hours'),
                TextInput::make('source')
                    ->required()
                    ->default('manual'),
                TextInput::make('osm_id'),
                Toggle::make('is_verified')
                    ->required(),
                Toggle::make('accepts_new')
                    ->required(),
                TextInput::make('rating')
                    ->numeric(),
                TextInput::make('review_count')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('quality_score')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('location'),
            ]);
    }
}
