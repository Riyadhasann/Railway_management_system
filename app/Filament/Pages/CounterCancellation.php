<?php

namespace App\Filament\Pages;

use App\Data\RefundQuote;
use App\Enums\BookingStatus;
use App\Enums\Permission;
use App\Enums\RefundReason;
use App\Exceptions\BookingRefusedException;
use App\Models\Booking;
use App\Models\ScheduleStop;
use App\Models\User;
use App\Services\RefundService;
use App\Services\SeatInventoryService;
use App\Support\Money;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Counter clerk cancels a confirmed booking by PNR. The refund follows the refund rules:
 * cash bookings are refunded in cash by the clerk, online bookings to the original method.
 */
class CounterCancellation extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static string|UnitEnum|null $navigationGroup = 'Bookings';

    protected static ?string $navigationLabel = 'Cancel and refund';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.counter-cancellation';

    public string $pnr = '';

    public ?int $bookingId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::CounterBooking->value) ?? false;
    }

    public function getTitle(): string
    {
        return 'Cancel and refund';
    }

    public function find(): void
    {
        $this->resetErrorBag();
        $pnr = strtoupper(trim($this->pnr));
        $booking = $pnr === '' ? null : Booking::where('pnr', $pnr)->first();

        if ($booking === null) {
            $this->bookingId = null;
            $this->addError('pnr', 'No booking has that PNR.');

            return;
        }

        $this->pnr = $pnr;
        $this->bookingId = $booking->id;
    }

    public function booking(): ?Booking
    {
        return $this->bookingId === null ? null : Booking::query()
            ->with(['trainRun.train.scheduleStops.routeStop.station', 'passengers.seatBooking.seat', 'payments', 'refunds'])
            ->find($this->bookingId);
    }

    public function stationName(Booking $booking, int $sequence): string
    {
        return $booking->trainRun->train->scheduleStops
            ->first(fn (ScheduleStop $s) => $s->routeStop->sequence === $sequence)
            ?->routeStop->station->name ?? '';
    }

    public function quote(Booking $booking): ?RefundQuote
    {
        if ($booking->status !== BookingStatus::Confirmed) {
            return null;
        }

        $departure = app(SeatInventoryService::class)->boardingDeparture($booking->trainRun, $booking->from_seq);

        return $departure?->isFuture() ? app(RefundService::class)->quote($booking) : null;
    }

    public function cancelBooking(RefundService $refunds): void
    {
        abort_unless(static::canAccess(), 403);
        $booking = $this->booking();

        if ($booking === null) {
            return;
        }

        /** @var User $clerk */
        $clerk = auth()->user();

        try {
            $refund = $refunds->cancel($booking, $clerk, RefundReason::CounterCancelled);
        } catch (BookingRefusedException $e) {
            Notification::make()->title('Not cancelled')->body($e->getMessage())->danger()->send();

            return;
        }

        $body = match (true) {
            $refund === null => 'No refund is due this close to departure.',
            $refund->payment->method->isOnline() => Money::format($refund->amount_poisha).' is being refunded to the passenger\'s '.$refund->payment->method->getLabel().' account.',
            default => 'Hand '.Money::format($refund->amount_poisha).' back to the passenger in cash.',
        };

        Notification::make()->title("Cancelled: PNR {$booking->pnr}")->body($body)->success()->persistent()->send();
    }
}
