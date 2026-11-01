<?php

namespace App\Filament\Resources\Trains\Pages;

use App\Filament\Resources\Trains\TrainResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTrain extends EditRecord
{
    protected static string $resource = TrainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Hidden by TrainPolicy once runs exist (deactivate the train instead).
            DeleteAction::make(),
        ];
    }
}
