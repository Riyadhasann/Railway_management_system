<?php

namespace App\Filament\Resources\CoachTypes\Pages;

use App\Filament\Resources\CoachTypes\CoachTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCoachTypes extends ListRecords
{
    protected static string $resource = CoachTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
