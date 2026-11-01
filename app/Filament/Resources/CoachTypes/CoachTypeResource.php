<?php

namespace App\Filament\Resources\CoachTypes;

use App\Filament\Resources\CoachTypes\Pages\CreateCoachType;
use App\Filament\Resources\CoachTypes\Pages\EditCoachType;
use App\Filament\Resources\CoachTypes\Pages\EditSeatLayout;
use App\Filament\Resources\CoachTypes\Pages\ListCoachTypes;
use App\Filament\Resources\CoachTypes\Schemas\CoachTypeForm;
use App\Filament\Resources\CoachTypes\Tables\CoachTypesTable;
use App\Models\CoachType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CoachTypeResource extends Resource
{
    protected static ?string $model = CoachType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Coaches';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return CoachTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CoachTypesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCoachTypes::route('/'),
            'create' => CreateCoachType::route('/create'),
            'edit' => EditCoachType::route('/{record}/edit'),
            'layout' => EditSeatLayout::route('/{record}/layout'),
        ];
    }
}
