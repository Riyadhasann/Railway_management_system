<?php

namespace App\Filament\Resources\Trains;

use App\Filament\Resources\Trains\Pages\CreateTrain;
use App\Filament\Resources\Trains\Pages\EditTrain;
use App\Filament\Resources\Trains\Pages\ListTrains;
use App\Filament\Resources\Trains\Schemas\TrainForm;
use App\Filament\Resources\Trains\Tables\TrainsTable;
use App\Models\Train;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class TrainResource extends Resource
{
    protected static ?string $model = Train::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Network';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return TrainForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TrainsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrains::route('/'),
            'create' => CreateTrain::route('/create'),
            'edit' => EditTrain::route('/{record}/edit'),
        ];
    }
}
