<?php

namespace App\Filament\Resources\CoachTypes\Tables;

use App\Enums\SeatClass;
use App\Filament\Resources\CoachTypes\CoachTypeResource;
use App\Models\CoachType;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CoachTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['seats', 'rakeCoaches']))
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')->searchable()->sortable()->badge(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('seat_class')->label('Class')->badge(),
                TextColumn::make('grid')
                    ->state(fn (CoachType $record) => "{$record->layout_rows} × {$record->layout_cols}"),
                TextColumn::make('seats_count')->label('Seats')->sortable(),
                TextColumn::make('rake_coaches_count')->label('Used in coaches'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('seat_class')->label('Class')->options(SeatClass::class),
            ])
            ->recordActions([
                Action::make('layout')
                    ->label('Seat layout')
                    ->icon(Heroicon::OutlinedSquares2x2)
                    ->url(fn (CoachType $record) => CoachTypeResource::getUrl('layout', ['record' => $record])),
                EditAction::make(),
            ]);
    }
}
