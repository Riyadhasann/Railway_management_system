<?php

namespace App\Filament\Resources\RefundRules;

use App\Filament\Resources\RefundRules\Pages\ManageRefundRules;
use App\Models\RefundRule;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Cancellation refunds by hours before departure. Edited in modals on one page.
 */
class RefundRuleResource extends Resource
{
    protected static ?string $model = RefundRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Fares';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('hours_before')
                    ->label('Hours before departure (at least)')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(8760)
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->columnSpanFull(),
                TextInput::make('percent')
                    ->label('Refund')
                    ->suffix('%')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(100)
                    ->required(),
                TextInput::make('fee_poisha')
                    ->label('Cancellation fee per booking')
                    ->prefix('৳')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->default(0)
                    ->required()
                    ->formatStateUsing(fn (?int $state) => $state === null ? null : $state / 100)
                    ->dehydrateStateUsing(fn (mixed $state) => Money::toPoisha((string) $state)),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('hours_before', 'desc')
            ->columns([
                TextColumn::make('hours_before')
                    ->label('Cancelled at least')
                    ->formatStateUsing(fn (int $state) => "{$state} h before departure")
                    ->sortable(),
                TextColumn::make('percent')->label('Refund')->suffix('%'),
                TextColumn::make('fee_poisha')->label('Fee')->formatStateUsing(fn (int $state) => Money::format($state)),
                TextColumn::make('updated_at')->label('Last changed')->since(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageRefundRules::route('/'),
        ];
    }
}
