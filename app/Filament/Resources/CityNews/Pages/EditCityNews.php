<?php

namespace App\Filament\Resources\CityNews\Pages;

use App\Filament\Resources\CityNews\CityNewsResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCityNews extends EditRecord
{
    protected static string $resource = CityNewsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
