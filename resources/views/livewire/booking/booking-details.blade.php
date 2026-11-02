@php
    use App\Enums\BookingStatus;
    use App\Support\Money;
    $run = $booking->trainRun;
    $stops = $this->stops;
    $departure = $this->departure();
    $arrival = $this->arrival();
    $quote = $this->refundQuote();
    $flash = session('payment_status');
@endphp

<div class="mx-auto w-full max-w-4xl space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:text>{{ __('PNR') }}</flux:text>
            <div class="flex flex-wrap items-center gap-3">
                <flux:heading size="xl" level="1" class="font-mono tracking-wider">{{ $booking->pnr }}</flux:heading>
                <flux:badge :color="$booking->status->badgeColor()" data-test="status">{{ $booking->status->getLabel() }}</flux:badge>
            </div>
            <flux:text class="mt-1">{{ $run->train->label() }}</flux:text>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($booking->hasTicket())
                <flux:button :href="route('tickets.pdf', $booking)" variant="primary" icon="arrow-down-tray" data-test="download">{{ __('Download ticket') }}</flux:button>
            @elseif ($booking->isHeld())
                <flux:button :href="route('booking.checkout', $booking)" variant="primary" icon="credit-card" wire:navigate>{{ __('Continue to payment') }}</flux:button>
            @endif
            <flux:button :href="route('booking.mine')" variant="ghost" wire:navigate>{{ __('All bookings') }}</flux:button>
        </div>
    </div>

    @if ($flash)
        @php [$variant, $text] = $flash; @endphp
        <flux:callout :variant="in_array($variant, ['success', 'warning', 'danger'], true) ? $variant : 'secondary'" icon="information-circle" data-test="payment-status">
            <flux:callout.text>{{ $text }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($notice)
        <flux:callout variant="success" icon="check-circle" data-test="notice">
            <flux:callout.text>{{ $notice }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($run->isCancelled())
        <flux:callout variant="danger" icon="exclamation-triangle">
            <flux:callout.heading>{{ __('This train has been cancelled') }}</flux:callout.heading>
            <flux:callout.text>{{ $run->cancel_reason }} {{ __('Paid fares are refunded in full automatically.') }}</flux:callout.text>
        </flux:callout>
    @endif

    <section class="grid gap-4 rounded-xl border border-zinc-200 p-4 sm:grid-cols-3 dark:border-zinc-700">
        <div>
            <flux:text class="text-xs uppercase tracking-wide">{{ __('From') }}</flux:text>
            <div class="text-lg font-semibold">{{ $stops->get($booking->from_seq)?->routeStop->station->name }}</div>
            <flux:text>{{ $departure?->format('D d M, H:i') }}</flux:text>
        </div>
        <div>
            <flux:text class="text-xs uppercase tracking-wide">{{ __('To') }}</flux:text>
            <div class="text-lg font-semibold">{{ $stops->get($booking->to_seq)?->routeStop->station->name }}</div>
            <flux:text>{{ $arrival?->format('D d M, H:i') }}</flux:text>
        </div>
        <div>
            <flux:text class="text-xs uppercase tracking-wide">{{ __('Total') }}</flux:text>
            <div class="text-lg font-semibold">{{ Money::format($booking->total_poisha) }}</div>
            <flux:text>{{ $booking->channel->getLabel() }}</flux:text>
        </div>
    </section>

    <section class="space-y-3">
        <flux:heading size="lg">{{ __('Passengers') }}</flux:heading>
        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 text-start text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-900">
                    <tr>
                        <th class="px-4 py-2 text-start">{{ __('Name') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Seat') }}</th>
                        <th class="px-4 py-2 text-start">{{ __('Age') }}</th>
                        <th class="px-4 py-2 text-end">{{ __('Fare') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($booking->passengers->sortBy('id') as $p)
                        <tr>
                            <td class="px-4 py-2">{{ $p->name }}@if ($p->concession) <span class="text-zinc-500">({{ $p->concession->name }})</span>@endif</td>
                            <td class="px-4 py-2">{{ $p->seatBooking->coach_no }}-{{ $p->seatBooking->seat->seat_no }}</td>
                            <td class="px-4 py-2">{{ $p->age }}</td>
                            <td class="px-4 py-2 text-end tabular-nums">{{ Money::format($p->fare_poisha) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-3 text-zinc-500">{{ __('No passengers saved.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if ($booking->payments->isNotEmpty() || $booking->refunds->isNotEmpty())
        <section class="space-y-3">
            <flux:heading size="lg">{{ __('Payments and refunds') }}</flux:heading>
            <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 text-sm dark:divide-zinc-700 dark:border-zinc-700">
                @foreach ($booking->payments->sortBy('id') as $payment)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-2" data-test="payment-row">
                        <span>{{ __('Payment') }} · {{ $payment->method->getLabel() }} · {{ $payment->status->getLabel() }}</span>
                        <span class="tabular-nums">{{ Money::format($payment->amount_poisha) }} <span class="text-zinc-500">{{ $payment->created_at?->format('d M H:i') }}</span></span>
                    </li>
                @endforeach
                @foreach ($booking->refunds->sortBy('id') as $refund)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-2" data-test="refund-row">
                        <span>{{ __('Refund') }} · {{ $refund->reason->getLabel() }} · {{ $refund->status->getLabel() }}</span>
                        <span class="tabular-nums">−{{ Money::format($refund->amount_poisha) }}@if ($refund->fee_poisha > 0) <span class="text-zinc-500">({{ __('fee :fee', ['fee' => Money::format($refund->fee_poisha)]) }})</span>@endif</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($quote)
        <section class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="cancel">
            <flux:heading size="lg">{{ __('Cancel this booking') }}</flux:heading>
            <flux:text>
                @if ($quote->isRefundable())
                    {{ __('Cancelling now refunds :amount (:percent% of :paid, less a :fee fee). The train leaves in about :hours hours.', [
                        'amount' => Money::format($quote->amountPoisha),
                        'percent' => $quote->percent,
                        'paid' => Money::format($quote->paidPoisha),
                        'fee' => Money::format($quote->feePoisha),
                        'hours' => (int) floor($quote->hoursBefore),
                    ]) }}
                @else
                    {{ __('The train leaves in about :hours hours, which is too close to departure for a refund. You can still cancel to free the seats.', ['hours' => (int) floor($quote->hoursBefore)]) }}
                @endif
            </flux:text>
            @error('cancel') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror
            <flux:button wire:click="cancelBooking" wire:confirm="{{ __('Cancel booking :pnr? This can not be undone.', ['pnr' => $booking->pnr]) }}" variant="danger" icon="x-mark">
                {{ $quote->isRefundable() ? __('Cancel and refund :amount', ['amount' => Money::format($quote->amountPoisha)]) : __('Cancel without refund') }}
            </flux:button>
        </section>
    @elseif ($booking->isHeld())
        <flux:button wire:click="releaseHold" wire:confirm="{{ __('Release these seats?') }}" variant="ghost">{{ __('Release held seats') }}</flux:button>
    @endif
</div>
