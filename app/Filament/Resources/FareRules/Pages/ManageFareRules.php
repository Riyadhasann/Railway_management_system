<?php

namespace App\Filament\Resources\FareRules\Pages;

use App\Filament\Resources\FareRules\FareRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageFareRules extends ManageRecords
{
    protected static string $resource = FareRuleResource::class;

    protected ?string $subheading = 'Fare per seat by class and distance. New searches use the new prices immediately.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
