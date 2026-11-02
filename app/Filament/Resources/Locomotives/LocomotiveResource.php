<?php

namespace App\Filament\Resources\Locomotives;

use App\Enums\LocoStatus;
use App\Enums\MaintenanceType;
use App\Filament\Resources\Locomotives\Pages\ManageLocomotives;
use App\Models\Locomotive;
use App\Services\MaintenanceService;
use BackedEnum;
use Carbon\CarbonImmutable;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Locomotives and their workshop visits. "Under repair" is set only by sending a loco
 * to the workshop and cleared by returning it to service.
 */
class LocomotiveResource extends Resource
{
    protected static ?string $model = Locomotive::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = 'Crew and locos';

    protected static ?string $navigationLabel = 'Locomotives';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'loco_no';

    /** Maintenance due within this many days is highlighted. */
    public const int DUE_WARNING_DAYS = 7;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('loco_no')->label('Loco no.')->required()->maxLength(20)->unique(ignoreRecord: true),
                TextInput::make('model')->required()->maxLength(50),
                Select::make('traction')->options(['diesel' => 'Diesel', 'electric' => 'Electric'])->default('diesel')->required(),
                TextInput::make('home_shed')->maxLength(50),
                Select::make('status')
                    ->options([LocoStatus::Available->value => LocoStatus::Available->getLabel(), LocoStatus::Retired->value => LocoStatus::Retired->getLabel()])
                    ->default(LocoStatus::Available->value)
                    ->required()
                    ->disabled(fn (?Locomotive $record) => $record?->status === LocoStatus::UnderRepair)
                    ->helperText('Use "Send to workshop" and "Return to service" for repairs.'),
                DatePicker::make('next_maintenance_due')->label('Next maintenance due'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('loco_no')
            ->columns([
                TextColumn::make('loco_no')->label('Loco no.')->searchable()->sortable()->weight('bold'),
                TextColumn::make('model')->searchable(),
                TextColumn::make('home_shed')->label('Shed')->toggleable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('last_maintenance_on')->label('Last maintenance')->date('d M Y')->placeholder('—')->toggleable(),
                TextColumn::make('next_maintenance_due')
                    ->label('Next due')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->sortable()
                    ->badge()
                    ->color(fn (Locomotive $record) => match (true) {
                        $record->next_maintenance_due === null => 'gray',
                        $record->next_maintenance_due->isPast() => 'danger',
                        $record->next_maintenance_due->lte(now()->addDays(self::DUE_WARNING_DAYS)) => 'warning',
                        default => 'success',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->options(LocoStatus::class),
                Filter::make('due_soon')
                    ->label('Maintenance due in '.self::DUE_WARNING_DAYS.' days or overdue')
                    ->query(fn (Builder $query) => $query->whereDate('next_maintenance_due', '<=', now()->addDays(self::DUE_WARNING_DAYS)->toDateString())),
            ])
            ->recordActions([
                Action::make('workshop')
                    ->label('Send to workshop')
                    ->icon(Heroicon::OutlinedWrench)
                    ->color('warning')
                    ->visible(fn (Locomotive $record) => $record->status === LocoStatus::Available)
                    ->authorize('update')
                    ->schema([
                        Select::make('type')->options(MaintenanceType::class)->required(),
                        DatePicker::make('started_on')->default(now()->toDateString())->maxDate(now())->required(),
                        TextInput::make('description')->maxLength(255),
                    ])
                    ->action(function (Locomotive $record, array $data, MaintenanceService $maintenance) {
                        $type = $data['type'] instanceof MaintenanceType ? $data['type'] : MaintenanceType::from($data['type']);

                        try {
                            $maintenance->start($record, $type, CarbonImmutable::parse($data['started_on']), $data['description'] ?? null);
                            Notification::make()->title("Loco {$record->loco_no} is in the workshop")->success()->send();
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('return')
                    ->label('Return to service')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (Locomotive $record) => $record->status === LocoStatus::UnderRepair)
                    ->authorize('update')
                    ->schema([
                        DatePicker::make('completed_on')->default(now()->toDateString())->maxDate(now())->required(),
                        DatePicker::make('next_due')->label('Next maintenance due')->default(now()->addDays(90)->toDateString())->required(),
                    ])
                    ->action(function (Locomotive $record, array $data, MaintenanceService $maintenance) {
                        try {
                            $maintenance->complete($record, CarbonImmutable::parse($data['completed_on']), CarbonImmutable::parse($data['next_due']));
                            Notification::make()->title("Loco {$record->loco_no} is back in service")->success()->send();
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('history')
                    ->label('History')
                    ->icon(Heroicon::OutlinedClock)
                    ->color('gray')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalHeading(fn (Locomotive $record) => "Workshop history · {$record->loco_no}")
                    ->modalContent(fn (Locomotive $record) => view('filament.locomotives.history', [
                        'visits' => $record->maintenances()->orderByDesc('started_on')->get(),
                    ])),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageLocomotives::route('/'),
        ];
    }
}
