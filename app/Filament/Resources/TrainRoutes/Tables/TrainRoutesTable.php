<?php

namespace App\Filament\Resources\TrainRoutes\Tables;

use App\Models\TrainRoute;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TrainRoutesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('stops.station')
                ->withCount(['stops', 'trains'])
                ->withMax('stops', 'distance_km'))
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')->searchable()->sortable()->badge(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('path')
                    ->label('Stations')
                    ->state(fn (TrainRoute $record) => $record->stops->map(fn ($s) => $s->station->code)->implode(' → '))
                    ->wrap(),
                TextColumn::make('stops_max_distance_km')->label('Length')->suffix(' km')->sortable(),
                TextColumn::make('trains_count')->label('Trains'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
