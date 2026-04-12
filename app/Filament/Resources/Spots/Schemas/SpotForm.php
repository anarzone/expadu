<?php

namespace App\Filament\Resources\Spots\Schemas;

use App\Enums\NoiseLevel;
use App\Enums\SpotCategory;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SpotForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                Select::make('category')
                    ->options(SpotCategory::class)
                    ->required(),
                Textarea::make('description')
                    ->columnSpanFull(),
                TextInput::make('address'),
                TextInput::make('wifi_speed'),
                Select::make('noise_level')
                    ->options(NoiseLevel::class),
                TextInput::make('time_limit_mins')
                    ->numeric(),
                TextInput::make('opening_hours'),
                TextInput::make('rating')
                    ->numeric(),
                TextInput::make('location'),
                TextInput::make('lat')
                    ->numeric(),
                TextInput::make('lng')
                    ->numeric(),
            ]);
    }
}
