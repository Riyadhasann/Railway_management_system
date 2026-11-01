<?php

namespace App\Filament\Resources\TrainRoutes\Schemas;

use App\Models\Station;
use App\Services\TimetableValidator;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TrainRouteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Route')
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->required()
                            ->maxLength(20)
                            ->unique(ignoreRecord: true)
                            ->dehydrateStateUsing(fn (?string $state) => strtoupper((string) $state))
                            ->helperText('e.g. DHK-CTG'),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->helperText('e.g. Dhaka to Chattogram'),
                        Textarea::make('description')->columnSpanFull(),
                        Toggle::make('is_active')->label('Active')->default(true),
                    ]),

                Section::make('Stations in order')
                    ->description('Drag to reorder. The first station is at 0 km; distances must increase.')
                    ->schema([
                        Repeater::make('stops')
                            ->hiddenLabel()
                            ->relationship('stops')
                            ->orderColumn('sequence')
                            ->reorderable()
                            ->minItems(2)
                            ->defaultItems(2)
                            ->addActionLabel('Add station')
                            ->columns(2)
                            ->schema([
                                Select::make('station_id')
                                    ->label('Station')
                                    ->options(fn () => Station::query()->orderBy('name')->get()
                                        ->mapWithKeys(fn (Station $s) => [$s->id => $s->label()]))
                                    ->searchable()
                                    ->required()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                                TextInput::make('distance_km')
                                    ->label('Distance from origin (km)')
                                    ->numeric()
                                    ->integer()
                                    ->minValue(0)
                                    ->required(),
                            ])
                            ->rules([
                                fn (TimetableValidator $validator): Closure => function (string $attribute, mixed $value, Closure $fail) use ($validator) {
                                    $stops = array_values(array_map(fn (array $row) => [
                                        'station_id' => $row['station_id'] ?? null,
                                        'distance_km' => $row['distance_km'] ?? null,
                                    ], is_array($value) ? $value : []));

                                    foreach ($validator->routeErrors($stops) as $error) {
                                        $fail($error);
                                    }
                                },
                            ]),
                    ]),
            ]);
    }
}
