<?php

namespace App\Filament\Resources\Stations\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StationForm
{
    public const array DIVISIONS = [
        'Barishal', 'Chattogram', 'Dhaka', 'Khulna', 'Mymensingh', 'Rajshahi', 'Rangpur', 'Sylhet',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->label('Station code')
                            ->required()
                            ->maxLength(8)
                            ->alphaNum()
                            ->unique(ignoreRecord: true)
                            ->dehydrateStateUsing(fn (?string $state) => strtoupper((string) $state))
                            ->helperText('Short code, e.g. DHK'),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('name_bn')
                            ->label('Name (Bangla)')
                            ->maxLength(255),
                        Select::make('division')
                            ->options(array_combine(self::DIVISIONS, self::DIVISIONS)),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ]),
            ]);
    }
}
