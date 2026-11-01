<?php

namespace App\Filament\Resources\TrainRoutes\Pages;

use App\Filament\Resources\TrainRoutes\TrainRouteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTrainRoutes extends ListRecords
{
    protected static string $resource = TrainRouteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
