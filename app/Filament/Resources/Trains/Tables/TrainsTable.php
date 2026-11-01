<?php

namespace App\Filament\Resources\Trains\Tables;

use App\Models\Train;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TrainsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['trainRoute', 'scheduleStops.routeStop.station'])
                ->withCount('scheduleStops'))
            ->defaultSort('number')
            ->columns([
                TextColumn::make('number')->searchable()->sortable()->badge(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('trainRoute.name')->label('Route')->sortable(),
                TextColumn::make('departs')
                    ->state(fn (Train $record) => self::endpoint($record, first: true)),
                TextColumn::make('arrives')
                    ->state(fn (Train $record) => self::endpoint($record, first: false)),
                TextColumn::make('running_days')
                    ->label('Runs')
                    ->state(fn (Train $record) => $record->runningDaysLabel()),
                TextColumn::make('schedule_stops_count')->label('Stops'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('train_route_id')->label('Route')->relationship('trainRoute', 'name'),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    private static function endpoint(Train $train, bool $first): string
    {
        $stop = $first ? $train->scheduleStops->first() : $train->scheduleStops->last();

        if ($stop === null) {
            return '—';
        }

        $time = substr((string) ($first ? $stop->departure_time : $stop->arrival_time), 0, 5);
        $nextDay = $stop->day_offset > 0 ? " (+{$stop->day_offset})" : '';

        return "{$stop->routeStop->station->code} {$time}{$nextDay}";
    }
}
