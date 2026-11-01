<?php

namespace App\Filament\Resources\Concessions\Pages;

use App\Filament\Resources\Concessions\ConcessionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageConcessions extends ManageRecords
{
    protected static string $resource = ConcessionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
