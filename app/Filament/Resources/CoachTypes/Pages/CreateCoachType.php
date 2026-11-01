<?php

namespace App\Filament\Resources\CoachTypes\Pages;

use App\Filament\Resources\CoachTypes\CoachTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCoachType extends CreateRecord
{
    protected static string $resource = CoachTypeResource::class;

    /**
     * After creating the type, go straight to drawing its seats.
     */
    protected function getRedirectUrl(): string
    {
        return CoachTypeResource::getUrl('layout', ['record' => $this->getRecord()]);
    }
}
