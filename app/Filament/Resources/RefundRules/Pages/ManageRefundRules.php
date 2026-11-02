<?php

namespace App\Filament\Resources\RefundRules\Pages;

use App\Filament\Resources\RefundRules\RefundRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageRefundRules extends ManageRecords
{
    protected static string $resource = RefundRuleResource::class;

    protected ?string $subheading = 'The rule with the most hours that still applies wins. Cancelling later than every rule gets no refund. Train cancellations are always refunded in full.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
