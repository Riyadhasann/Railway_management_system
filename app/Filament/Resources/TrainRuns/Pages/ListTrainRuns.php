<?php

namespace App\Filament\Resources\TrainRuns\Pages;

use App\Filament\Resources\TrainRuns\TrainRunResource;
use App\Models\TrainRun;
use App\Services\TrainRunService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

class ListTrainRuns extends ListRecords
{
    protected static string $resource = TrainRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->label('Generate runs')
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn () => Gate::allows('create', TrainRun::class))
                ->action(function (TrainRunService $runs) {
                    $created = $runs->generate();
                    $days = config('railway.runs_ahead_days');

                    Notification::make()
                        ->title("Created {$created} run(s) for the next {$days} days")
                        ->success()
                        ->send();
                }),
        ];
    }
}
