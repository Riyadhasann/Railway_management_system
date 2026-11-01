<?php

namespace App\Filament\Resources\Trains\Pages;

use App\Filament\Resources\Trains\TrainResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTrains extends ListRecords
{
    protected static string $resource = TrainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
