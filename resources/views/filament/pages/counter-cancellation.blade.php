@php
    use App\Support\Money;
    $booking = $this->booking();
    $quote = $booking ? $this->quote($booking) : null;
    $field = 'display:flex; flex-direction:column; gap:.25rem; font-size:.875rem;';
    $muted = 'color: rgb(113 113 122); font-size:.8125rem;';
@endphp

<x-filament-panels::page>
    <x-filament::section>
        <form wire:submit="find" style="display:flex; flex-wrap:wrap; gap:1rem; align-items:flex-end;">
            <label style="{{ $field }} min-width:12rem;">
                PNR
                <x-filament::input.wrapper :valid="! $errors->has('pnr')">
                    <x-filament::input type="text" wire:model="pnr" maxlength="10" autocomplete="off" style="text-transform:uppercase; letter-spacing:.1em;" />
                </x-filament::input.wrapper>
            </label>
            <x-filament::button type="submit" icon="heroicon-o-magnifying-glass">Find booking</x-filament::button>
        </form>
        @error('pnr') <p style="color: rgb(220 38 38); font-size:.75rem; margin-top:.5rem;">{{ $message }}</p> @enderror
    </x-filament::section>

    @if ($booking)
        <x-filament::section>
            <x-slot name="heading">
                PNR {{ $booking->pnr }} ·
                <x-filament::badge :color="$booking->hasTicket() ? 'success' : 'gray'" style="display:inline-flex;">{{ $booking->status->getLabel() }}</x-filament::badge>
            </x-slot>
            <x-slot name="description">
                {{ $booking->trainRun->train->label() }} · {{ $this->stationName($booking, $booking->from_seq) }} → {{ $this->stationName($booking, $booking->to_seq) }}
                · {{ $booking->trainRun->journey_date->format('D d M Y') }} · {{ $booking->channel->getLabel() }}
            </x-slot>

            <p style="font-size:.875rem;">
                {{ $booking->passengers->sortBy('id')->map(fn ($p) => $p->name.' ('.$p->seatBooking->coach_no.'-'.$p->seatBooking->seat->seat_no.')')->join(', ') }}
                · <strong>Paid {{ Money::format($booking->total_poisha) }}</strong>
                @if ($payment = $booking->paidPayment()) by {{ $payment->method->getLabel() }} @endif
            </p>

            @foreach ($booking->refunds as $refund)
                <p style="{{ $muted }} margin-top:.5rem;">
                    Refund {{ Money::format($refund->amount_poisha) }} · {{ $refund->reason->getLabel() }} · {{ $refund->status->getLabel() }}
                </p>
            @endforeach

            @if ($quote)
                <div style="margin-top:1rem; padding-top:1rem; border-top:1px solid rgb(228 228 231);">
                    @if ($quote->isRefundable())
                        <p style="font-size:.875rem;">
                            Departs in about {{ (int) floor($quote->hoursBefore) }} h: refund <strong>{{ Money::format($quote->amountPoisha) }}</strong>
                            ({{ $quote->percent }}% of {{ Money::format($quote->paidPoisha) }}, less {{ Money::format($quote->feePoisha) }} fee).
                        </p>
                    @else
                        <p style="font-size:.875rem;">Departs in about {{ (int) floor($quote->hoursBefore) }} h: too close to departure for a refund.</p>
                    @endif
                    <div style="margin-top:.75rem; display:flex; gap:.5rem; flex-wrap:wrap;">
                        <x-filament::button color="danger" icon="heroicon-o-x-circle" wire:click="cancelBooking"
                            wire:confirm="Cancel PNR {{ $booking->pnr }}? The seats go back on sale.">
                            {{ $quote->isRefundable() ? 'Cancel and refund '.Money::format($quote->amountPoisha) : 'Cancel without refund' }}
                        </x-filament::button>
                        <x-filament::button tag="a" :href="route('tickets.pdf', $booking)" color="gray" icon="heroicon-o-printer" target="_blank">
                            Print ticket
                        </x-filament::button>
                    </div>
                </div>
            @else
                <p style="{{ $muted }} margin-top:1rem;">
                    @if ($booking->hasTicket())
                        The train has left the boarding station, so this booking can no longer be cancelled.
                    @else
                        Only confirmed bookings can be cancelled.
                    @endif
                </p>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
