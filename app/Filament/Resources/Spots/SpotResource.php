<?php

namespace App\Filament\Resources\Spots;

use App\Filament\Resources\Spots\Pages\CreateSpot;
use App\Filament\Resources\Spots\Pages\EditSpot;
use App\Filament\Resources\Spots\Pages\ListSpots;
use App\Filament\Resources\Spots\Schemas\SpotForm;
use App\Filament\Resources\Spots\Tables\SpotsTable;
use App\Models\Spot;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SpotResource extends Resource
{
    protected static ?string $model = Spot::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return SpotForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SpotsTable::configure($table);
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
            'index' => ListSpots::route('/'),
            'create' => CreateSpot::route('/create'),
            'edit' => EditSpot::route('/{record}/edit'),
        ];
    }
}
