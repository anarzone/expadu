<?php

namespace App\Filament\Resources\CityNews\Pages;

use App\Filament\Resources\CityNews\CityNewsResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCityNews extends ListRecords
{
    protected static string $resource = CityNewsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
