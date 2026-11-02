<?php

namespace App\Filament\Resources\Locomotives\Pages;

use App\Filament\Resources\Locomotives\LocomotiveResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageLocomotives extends ManageRecords
{
    protected static string $resource = LocomotiveResource::class;

    protected ?string $subheading = 'Locos under repair can\'t be assigned to a train.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
