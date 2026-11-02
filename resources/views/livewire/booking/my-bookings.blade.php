@php use App\Support\Money; @endphp

<div class="mx-auto w-full max-w-4xl space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('My bookings') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Your tickets, newest first.') }}</flux:text>
        </div>
        <flux:button :href="route('booking.search')" variant="primary" icon="magnifying-glass" wire:navigate>{{ __('Book tickets') }}</flux:button>
    </div>

    @forelse ($this->bookings as $booking)
        <a wire:key="booking-{{ $booking->id }}" href="{{ route('booking.show', $booking) }}" wire:navigate
           class="block rounded-xl border border-zinc-200 p-4 transition hover:border-zinc-400 focus-visible:outline-2 focus-visible:outline-offset-2 dark:border-zinc-700 dark:hover:border-zinc-500"
           data-test="booking-row">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-mono text-lg font-semibold tracking-wider">{{ $booking->pnr }}</span>
                        <flux:badge size="sm" :color="$booking->status->badgeColor()">{{ $booking->status->getLabel() }}</flux:badge>
                        @if ($booking->hasTicket() && $booking->trainRun->progressLabel())
                            <flux:badge size="sm" :color="$booking->trainRun->delay_minutes > 0 ? 'amber' : 'zinc'" icon="clock">{{ $booking->trainRun->progressLabel() }}</flux:badge>
                        @endif
                    </div>
                    <flux:text class="mt-1">
                        {{ $booking->trainRun->train->label() }} · {{ $this->stationName($booking, $booking->from_seq) }} → {{ $this->stationName($booking, $booking->to_seq) }}
                    </flux:text>
                </div>
                <div class="text-end">
                    <div class="font-medium">{{ $booking->trainRun->journey_date->format('D d M Y') }}</div>
                    <flux:text>{{ trans_choice(':count passenger|:count passengers', $booking->passengers_count) }} · {{ Money::format($booking->total_poisha) }}</flux:text>
                </div>
            </div>
        </a>
    @empty
        <flux:callout icon="ticket">
            <flux:callout.text>{{ __('You have no bookings yet.') }}</flux:callout.text>
        </flux:callout>
    @endforelse
</div>
