<?php

namespace App\Filament\Resources\FareRules;

use App\Enums\SeatClass;
use App\Filament\Resources\FareRules\Pages\ManageFareRules;
use App\Models\FareRule;
use App\Services\FareService;
use App\Support\Money;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Fare per seat by class and distance band. Edited in modals on one page.
 */
class FareRuleResource extends Resource
{
    protected static ?string $model = FareRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Fares';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('seat_class')
                    ->label('Class')
                    ->options(SeatClass::class)
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('km_from')
                    ->label('From km')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(9999)
                    ->required(),
                TextInput::make('km_to')
                    ->label('To km')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(9999)
                    ->required()
                    ->helperText('Inclusive. Bands of one class must not overlap.')
                    ->rule(fn (Get $get, ?FareRule $record) => function (string $attribute, mixed $value, Closure $fail) use ($get, $record) {
                        $class = $get('seat_class');
                        $class = $class instanceof SeatClass ? $class : SeatClass::tryFrom((string) $class);

                        if ($class === null || ! is_numeric($get('km_from')) || ! is_numeric($value)) {
                            return;
                        }

                        foreach (app(FareService::class)->bandErrors($class, (int) $get('km_from'), (int) $value, $record?->id) as $error) {
                            $fail($error);
                        }
                    }),
                TextInput::make('fare_poisha')
                    ->label('Fare per seat')
                    ->prefix('৳')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->required()
                    ->formatStateUsing(fn (?int $state) => $state === null ? null : $state / 100)
                    ->dehydrateStateUsing(fn (mixed $state) => Money::toPoisha((string) $state))
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->orderBy('seat_class')->orderBy('km_from'))
            ->columns([
                TextColumn::make('seat_class')->label('Class')->badge(),
                TextColumn::make('band')
                    ->label('Distance')
                    ->state(fn (FareRule $record) => "{$record->km_from}–{$record->km_to} km"),
                TextColumn::make('fare_poisha')
                    ->label('Fare per seat')
                    ->formatStateUsing(fn (int $state) => Money::format($state)),
                TextColumn::make('updated_at')->label('Last changed')->since(),
            ])
            ->filters([
                SelectFilter::make('seat_class')->label('Class')->options(SeatClass::class),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFareRules::route('/'),
        ];
    }
}
