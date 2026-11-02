<?php

namespace App\Filament\Resources\Staff\Pages;

use App\Filament\Resources\Staff\StaffResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageStaff extends ManageRecords
{
    protected static string $resource = StaffResource::class;

    protected ?string $subheading = 'Drivers and assistant drivers need a valid licence to be assigned to a train.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
