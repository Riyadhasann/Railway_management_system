<?php

namespace App\Filament\Resources\TrainRoutes\Pages;

use App\Filament\Resources\TrainRoutes\TrainRouteResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTrainRoute extends EditRecord
{
    protected static string $resource = TrainRouteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Hidden by TrainRoutePolicy when trains use this route.
            DeleteAction::make(),
        ];
    }
}
