<?php

namespace App\Filament\Resources\CoachTypes\Schemas;

use App\Enums\SeatClass;
use App\Models\CoachType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CoachTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->required()
                            ->maxLength(20)
                            ->unique(ignoreRecord: true)
                            ->dehydrateStateUsing(fn (?string $state) => strtoupper((string) $state))
                            ->helperText('e.g. S_CHAIR'),
                        TextInput::make('name')->required()->maxLength(255),
                        Select::make('seat_class')
                            ->label('Class')
                            ->options(SeatClass::class)
                            ->required(),
                        Toggle::make('is_active')->label('Active')->default(true)->inline(false),
                        TextInput::make('layout_rows')
                            ->label('Grid rows')
                            ->numeric()->integer()->required()
                            ->default(12)
                            ->minValue(fn (?CoachType $record) => max(1, (int) $record?->seats()->max('row')))
                            ->maxValue(40)
                            ->helperText('Can\'t be smaller than the last row that has a seat.'),
                        TextInput::make('layout_cols')
                            ->label('Grid columns')
                            ->numeric()->integer()->required()
                            ->default(5)
                            ->minValue(fn (?CoachType $record) => max(1, (int) $record?->seats()->max('col')))
                            ->maxValue(10)
                            ->helperText('Include aisle columns.'),
                    ]),
            ]);
    }
}
