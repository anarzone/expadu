<?php

namespace App\Filament\Resources\CityNews;

use App\Filament\Resources\CityNews\Pages\CreateCityNews;
use App\Filament\Resources\CityNews\Pages\EditCityNews;
use App\Filament\Resources\CityNews\Pages\ListCityNews;
use App\Filament\Resources\CityNews\Schemas\CityNewsForm;
use App\Filament\Resources\CityNews\Tables\CityNewsTable;
use App\Models\CityNews;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CityNewsResource extends Resource
{
    protected static ?string $model = CityNews::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return CityNewsForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CityNewsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCityNews::route('/'),
            'create' => CreateCityNews::route('/create'),
            'edit' => EditCityNews::route('/{record}/edit'),
        ];
    }
}
