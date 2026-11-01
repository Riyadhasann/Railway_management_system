<?php

namespace App\Filament\Resources\CoachTypes\Pages;

use App\Filament\Resources\CoachTypes\CoachTypeResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditCoachType extends EditRecord
{
    protected static string $resource = CoachTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('layout')
                ->label('Seat layout')
                ->icon(Heroicon::OutlinedSquares2x2)
                ->url(fn () => CoachTypeResource::getUrl('layout', ['record' => $this->getRecord()])),
            // Hidden by CoachTypePolicy when trains use this coach type.
            DeleteAction::make(),
        ];
    }
}
