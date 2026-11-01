<?php

namespace App\Filament\Resources\TrainRuns;

use App\Filament\Resources\TrainRuns\Pages\ListTrainRuns;
use App\Filament\Resources\TrainRuns\Tables\TrainRunsTable;
use App\Models\TrainRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Daily train runs. Created by runs:generate (never by hand) and cancelled from the list.
 */
class TrainRunResource extends Resource
{
    protected static ?string $model = TrainRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Network';

    protected static ?int $navigationSort = 4;

    public static function table(Table $table): Table
    {
        return TrainRunsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false; // runs come from the generator, not a form
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrainRuns::route('/'),
        ];
    }
}
