<?php

namespace App\Filament\Resources\Concessions;

use App\Filament\Resources\Concessions\Pages\ManageConcessions;
use App\Models\Concession;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Per-passenger discounts. Edited in modals on one page.
 */
class ConcessionResource extends Resource
{
    protected static ?string $model = Concession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Fares';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('code')
                    ->required()
                    ->maxLength(20)
                    ->alphaDash()
                    ->unique(ignoreRecord: true)
                    ->dehydrateStateUsing(fn (?string $state) => strtoupper((string) $state)),
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('percent')
                    ->label('Discount')
                    ->suffix('%')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(100)
                    ->required(),
                Toggle::make('is_active')->label('Active')->default(true)->inline(false),
                TextInput::make('min_age')->label('Minimum age')->integer()->minValue(0)->maxValue(120),
                TextInput::make('max_age')->label('Maximum age')->integer()->minValue(0)->maxValue(120)->gte('min_age'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')->badge()->searchable()->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('percent')->label('Discount')->suffix('%')->sortable(),
                TextColumn::make('min_age')->label('Min age')->placeholder('—'),
                TextColumn::make('max_age')->label('Max age')->placeholder('—'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageConcessions::route('/'),
        ];
    }
}
