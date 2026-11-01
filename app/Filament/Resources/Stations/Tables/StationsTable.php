<?php

namespace App\Filament\Resources\Stations\Tables;

use App\Filament\Resources\Stations\Schemas\StationForm;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class StationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('code')->searchable()->sortable()->badge(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('name_bn')->label('Bangla')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('division')->sortable(),
                TextColumn::make('route_stops_count')->counts('routeStops')->label('Routes'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('division')
                    ->options(array_combine(StationForm::DIVISIONS, StationForm::DIVISIONS)),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
