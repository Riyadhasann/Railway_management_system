<?php

namespace App\Filament\Resources\Staff;

use App\Enums\StaffDesignation;
use App\Filament\Resources\Staff\Pages\ManageStaff;
use App\Models\Staff;
use App\Models\User;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Train crew. Edited in modals on one page.
 */
class StaffResource extends Resource
{
    protected static ?string $model = Staff::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Crew and locos';

    protected static ?string $navigationLabel = 'Staff';

    protected static ?string $modelLabel = 'staff member';

    protected static ?string $pluralModelLabel = 'staff';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    /** Licences expiring within this many days are highlighted. */
    public const int EXPIRY_WARNING_DAYS = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('staff_no')->label('Staff no.')->required()->maxLength(20)->unique(ignoreRecord: true),
                TextInput::make('name')->required()->maxLength(100),
                Select::make('designation')->options(StaffDesignation::class)->required()->live(),
                TextInput::make('phone')->tel()->maxLength(20),
                Select::make('home_station_id')->label('Home station')->relationship('homeStation', 'name')->searchable()->preload(),
                Select::make('user_id')
                    ->label('Login account')
                    ->helperText('For a TTE who signs in to scan tickets.')
                    ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->unique(ignoreRecord: true),
                TextInput::make('licence_no')
                    ->label('Driving licence no.')
                    ->maxLength(30)
                    ->required(fn (Get $get) => self::needsLicence($get('designation'))),
                DatePicker::make('licence_expires_on')
                    ->label('Licence expires')
                    ->required(fn (Get $get) => self::needsLicence($get('designation'))),
                Toggle::make('is_active')->label('Active')->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['homeStation', 'user']))
            ->defaultSort('staff_no')
            ->columns([
                TextColumn::make('staff_no')->label('Staff no.')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('designation')->badge()->sortable(),
                TextColumn::make('homeStation.name')->label('Home station')->toggleable(),
                TextColumn::make('phone')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('licence_expires_on')
                    ->label('Licence expires')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->sortable()
                    ->badge()
                    ->color(fn (Staff $record) => match (true) {
                        $record->licence_expires_on === null => 'gray',
                        $record->licence_expires_on->isPast() => 'danger',
                        $record->licence_expires_on->lte(now()->addDays(self::EXPIRY_WARNING_DAYS)) => 'warning',
                        default => 'success',
                    })
                    ->description(fn (Staff $record) => $record->licence_expires_on?->isPast() ? 'Expired' : null),
                TextColumn::make('user.email')->label('Login')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('designation')->options(StaffDesignation::class),
                Filter::make('licence_expiring')
                    ->label('Licence expired or expiring in '.self::EXPIRY_WARNING_DAYS.' days')
                    ->query(fn (Builder $query) => $query->whereDate('licence_expires_on', '<=', now()->addDays(self::EXPIRY_WARNING_DAYS)->toDateString())),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageStaff::route('/'),
        ];
    }

    private static function needsLicence(mixed $designation): bool
    {
        $designation = $designation instanceof StaffDesignation ? $designation : StaffDesignation::tryFrom((string) $designation);

        return $designation?->needsLicence() ?? false;
    }
}
