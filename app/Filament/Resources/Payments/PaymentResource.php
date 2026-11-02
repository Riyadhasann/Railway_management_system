<?php

namespace App\Filament\Resources\Payments;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Models\Payment;
use App\Support\Money;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Transaction list: every payment attempt, online and cash. Read-only.
 */
class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Transactions';

    protected static ?string $modelLabel = 'transaction';

    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['booking.trainRun.train', 'receiver']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Started')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('booking.pnr')->label('PNR')->searchable()->copyable()->weight('bold'),
                TextColumn::make('booking.trainRun.train.number')->label('Train')->badge()->color('gray'),
                TextColumn::make('method')->badge(),
                TextColumn::make('amount_poisha')->label('Amount')->formatStateUsing(fn (int $state) => Money::format($state))->alignEnd()->sortable(),
                TextColumn::make('refunded_poisha')->label('Refunded')->formatStateUsing(fn (int $state) => $state > 0 ? Money::format($state) : '—')->alignEnd(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('txn_id')->label('Gateway txn')->searchable()->copyable()->placeholder('—')->toggleable(),
                TextColumn::make('reference')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('paid_at')->label('Paid')->dateTime('d M Y H:i')->placeholder('—')->sortable()->toggleable(),
                TextColumn::make('receiver.name')->label('Cash taken by')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('failure_reason')->label('Note')->limit(40)->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(PaymentStatus::class),
                SelectFilter::make('method')->options(PaymentMethod::class),
                Filter::make('dates')
                    ->schema([
                        DatePicker::make('from')->label('Started from'),
                        DatePicker::make('until')->label('Started until'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '<=', $d))),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
        ];
    }
}
