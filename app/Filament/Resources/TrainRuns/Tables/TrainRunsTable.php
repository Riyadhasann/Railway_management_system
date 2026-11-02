<?php

namespace App\Filament\Resources\TrainRuns\Tables;

use App\Enums\RunStatus;
use App\Filament\Resources\TrainRuns\TrainRunResource;
use App\Models\TrainRun;
use App\Services\TrainRunService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TrainRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'train.trainRoute',
                'train.scheduleStops.routeStop.station',
            ]))
            ->defaultSort('journey_date')
            ->columns([
                TextColumn::make('journey_date')->label('Date')->date('D, d M Y')->sortable(),
                TextColumn::make('train.number')->label('Train')->badge()->searchable(),
                TextColumn::make('train.name')->label('Name')->searchable(),
                TextColumn::make('train.trainRoute.name')->label('Route'),
                TextColumn::make('departure')
                    ->label('Departs')
                    ->state(fn (TrainRun $record) => $record->scheduledDeparture()?->format('H:i') ?? '—'),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('delay_minutes')
                    ->label('Delay')
                    ->formatStateUsing(fn (int $state) => $state > 0 ? "{$state} min" : '—'),
                TextColumn::make('cancel_reason')->label('Cancel reason')->limit(40)->toggleable(),
            ])
            ->filters([
                Filter::make('dates')
                    ->schema([
                        DatePicker::make('from')->default(now()->toDateString()),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('journey_date', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('journey_date', '<=', $d))),
                SelectFilter::make('status')->options(RunStatus::class),
                SelectFilter::make('train_id')->label('Train')->relationship('train', 'name'),
            ])
            ->recordActions([
                Action::make('seats')
                    ->label('Seat map')
                    ->icon(Heroicon::OutlinedSquares2x2)
                    ->color('gray')
                    ->url(fn (TrainRun $record) => TrainRunResource::getUrl('seats', ['record' => $record])),
                Action::make('cancel')
                    ->label('Cancel run')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (TrainRun $record) => $record->status === RunStatus::Scheduled)
                    ->authorize('update')
                    ->requiresConfirmation()
                    ->modalDescription('Unpaid holds are released and every confirmed passenger gets a full refund automatically.')
                    ->schema([
                        Textarea::make('reason')->required()->maxLength(255),
                    ])
                    ->action(function (TrainRun $record, array $data, TrainRunService $runs) {
                        try {
                            $runs->cancel($record, $data['reason']);
                            Notification::make()->title('Run cancelled')->success()->send();
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }
}
