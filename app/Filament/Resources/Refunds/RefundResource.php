<?php

namespace App\Filament\Resources\Refunds;

use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Filament\Resources\Refunds\Pages\ListRefunds;
use App\Models\Refund;
use App\Services\RefundService;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Refunds from cancellations, cancelled trains and late or duplicate payments. Read-only,
 * except that finance can retry a refund the gateway refused.
 */
class RefundResource extends Resource
{
    protected static ?string $model = Refund::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['booking', 'payment', 'requester']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Requested')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('booking.pnr')->label('PNR')->searchable()->copyable()->weight('bold'),
                TextColumn::make('reason')->badge()->color('gray'),
                TextColumn::make('payment.method')->label('To')->badge(),
                TextColumn::make('amount_poisha')->label('Amount')->formatStateUsing(fn (int $state) => Money::format($state))->alignEnd()->sortable(),
                TextColumn::make('percent')->suffix('%')->toggleable(),
                TextColumn::make('fee_poisha')->label('Fee')->formatStateUsing(fn (int $state) => $state > 0 ? Money::format($state) : '—')->toggleable(),
                TextColumn::make('hours_before')->label('Hours before')->formatStateUsing(fn (?string $state) => $state === null ? '—' : number_format((float) $state, 1))->toggleable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('txn_id')->label('Gateway txn')->placeholder('—')->toggleable(),
                TextColumn::make('requester.name')->label('By')->placeholder('System')->toggleable(),
                TextColumn::make('failure_reason')->label('Error')->limit(40)->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(RefundStatus::class),
                SelectFilter::make('reason')->options(RefundReason::class),
            ])
            ->recordActions([
                Action::make('retry')
                    ->label('Retry refund')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (Refund $record) => $record->status === RefundStatus::Failed)
                    ->authorize('update')
                    ->requiresConfirmation()
                    ->action(function (Refund $record, RefundService $refunds) {
                        $result = $refunds->process($record);

                        $result->status === RefundStatus::Processed
                            ? Notification::make()->title('Refund sent')->success()->send()
                            : Notification::make()->title('Refund failed again')->body($result->failure_reason)->danger()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRefunds::route('/'),
        ];
    }
}
