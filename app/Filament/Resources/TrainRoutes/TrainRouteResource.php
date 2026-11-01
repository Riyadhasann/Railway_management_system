<?php

namespace App\Filament\Resources\TrainRoutes;

use App\Filament\Resources\TrainRoutes\Pages\CreateTrainRoute;
use App\Filament\Resources\TrainRoutes\Pages\EditTrainRoute;
use App\Filament\Resources\TrainRoutes\Pages\ListTrainRoutes;
use App\Filament\Resources\TrainRoutes\Schemas\TrainRouteForm;
use App\Filament\Resources\TrainRoutes\Tables\TrainRoutesTable;
use App\Models\TrainRoute;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class TrainRouteResource extends Resource
{
    protected static ?string $model = TrainRoute::class;

    protected static ?string $modelLabel = 'route';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static string|UnitEnum|null $navigationGroup = 'Network';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return TrainRouteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TrainRoutesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrainRoutes::route('/'),
            'create' => CreateTrainRoute::route('/create'),
            'edit' => EditTrainRoute::route('/{record}/edit'),
        ];
    }
}
