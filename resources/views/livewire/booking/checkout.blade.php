@php
    use App\Support\Money;
    $run = $booking->trainRun;
    $stops = $this->stops;
    $editable = $this->isEditable();
    $statusMessage = $this->statusMessage();
@endphp

<div class="mx-auto w-full max-w-4xl space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Checkout') }} · {{ $booking->pnr }}</flux:heading>
            <flux:text class="mt-1">
                {{ $run->train->label() }} · {{ $stops[$booking->from_seq] ?? '' }} → {{ $stops[$booking->to_seq] ?? '' }}
                · {{ $run->journey_date->format('D d M Y') }}
            </flux:text>
        </div>

        @if ($editable)
            <div x-data="{ left: {{ $booking->holdSecondsLeft() }}, timer: null }"
                 x-init="timer = setInterval(() => { if (left > 0) { left-- } else { clearInterval(timer); $wire.expire() } }, 1000)"
                 class="rounded-lg bg-amber-50 px-4 py-2 text-amber-900 dark:bg-amber-500/15 dark:text-amber-200"
                 role="timer" aria-live="polite" data-test="countdown">
                <div class="text-xs uppercase tracking-wide">{{ __('Seats held for') }}</div>
                <div class="text-2xl font-semibold tabular-nums" x-text="String(Math.floor(left / 60)).padStart(2, '0') + ':' + String(left % 60).padStart(2, '0')">
                    {{ gmdate('i:s', $booking->holdSecondsLeft()) }}
                </div>
            </div>
        @endif
    </div>

    @if ($statusMessage)
        <flux:callout :variant="$booking->status === \App\Enums\BookingStatus::Confirmed ? 'success' : 'warning'" icon="information-circle">
            <flux:callout.text>{{ $statusMessage }}</flux:callout.text>
        </flux:callout>
        <flux:button :href="route('booking.search')" variant="primary" wire:navigate>{{ __('Search trains') }}</flux:button>
    @else
        @error('booking')
            <flux:callout variant="danger" icon="x-circle"><flux:callout.text>{{ $message }}</flux:callout.text></flux:callout>
        @enderror

        <section class="space-y-4">
            <flux:heading size="lg">{{ __('Passengers') }}</flux:heading>
            @error('passengers') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror

            @foreach ($this->seats as $i => $seat)
                <div wire:key="passenger-{{ $seat->id }}" class="grid gap-3 rounded-xl border border-zinc-200 p-4 sm:grid-cols-6 dark:border-zinc-700">
                    <div class="sm:col-span-6 flex items-center gap-2 text-sm font-medium">
                        {{ __('Seat') }} {{ $seat->coach_no }}-{{ $seat->seat->seat_no }}
                        @if ($seat->seat->hasFlag(\App\Models\Seat::FLAG_WOMEN)) <flux:badge size="sm" color="pink">{{ __('Women only') }}</flux:badge> @endif
                    </div>
                    <div class="sm:col-span-2"><flux:input wire:model="passengers.{{ $i }}.name" :label="__('Full name')" /></div>
                    <div><flux:input type="number" min="0" max="120" wire:model="passengers.{{ $i }}.age" :label="__('Age')" /></div>
                    <div>
                        <flux:select wire:model="passengers.{{ $i }}.gender" :label="__('Gender')" :placeholder="__('Choose')">
                            @foreach ($this->genders() as $value => $label)
                                <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                    <div class="sm:col-span-2"><flux:input wire:model="passengers.{{ $i }}.nid" :label="__('NID (18+)')" :placeholder="__('10, 13 or 17 digits')" /></div>
                    <div class="sm:col-span-3">
                        <flux:select wire:model="passengers.{{ $i }}.concession_id" :label="__('Concession')">
                            <flux:select.option value="">{{ __('None') }}</flux:select.option>
                            @foreach ($this->concessions as $concession)
                                <flux:select.option :value="$concession->id">{{ $concession->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                </div>
            @endforeach
        </section>

        <section class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="lg">{{ __('Mobile number') }}</flux:heading>
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-56"><flux:input wire:model="phone" :label="__('Mobile')" placeholder="01712345678" inputmode="numeric" /></div>
                @if ($verifiedPhone !== null && $verifiedPhone === $phone)
                    <flux:badge color="green" icon="check">{{ __('Confirmed') }}</flux:badge>
                @else
                    <flux:button wire:click="sendOtp">{{ __('Send code') }}</flux:button>
                @endif
            </div>
            @if ($otpShown)
                <flux:callout icon="chat-bubble-left-ellipsis">
                    <flux:callout.text>{{ __('Prototype: SMS is not sent. Your code is') }} <strong data-test="otp-code">{{ $otpShown }}</strong></flux:callout.text>
                </flux:callout>
                <div class="flex flex-wrap items-end gap-3">
                    <div class="w-40"><flux:input wire:model="otp" :label="__('Code')" inputmode="numeric" maxlength="6" /></div>
                    <flux:button wire:click="verifyOtp" variant="primary">{{ __('Confirm') }}</flux:button>
                </div>
            @endif
        </section>

        <section class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div>
                @if ($this->hasPassengers())
                    <div class="text-sm text-zinc-500">{{ __('Total to pay') }}</div>
                    <div class="text-2xl font-semibold" data-test="total">{{ Money::format($booking->total_poisha) }}</div>
                    <div class="text-sm text-zinc-500">
                        @foreach ($booking->passengers()->with('concession')->get() as $p)
                            {{ $p->name }}: {{ Money::format($p->fare_poisha) }}@if ($p->concession) ({{ $p->concession->name }})@endif@if (! $loop->last), @endif
                        @endforeach
                    </div>
                @else
                    <div class="text-sm text-zinc-500">{{ __('Base fare') }}</div>
                    <div class="text-2xl font-semibold">{{ Money::format($booking->total_poisha) }}</div>
                    <div class="text-sm text-zinc-500">{{ __('Concessions are applied when you save the passengers.') }}</div>
                @endif
            </div>
            <div class="flex flex-wrap gap-2">
                <flux:button wire:click="cancel" wire:confirm="{{ __('Release these seats?') }}" variant="ghost">{{ __('Cancel') }}</flux:button>
                <flux:button wire:click="save" variant="primary" icon="check">{{ __('Save passengers') }}</flux:button>
            </div>
        </section>

        @if ($this->hasPassengers())
            <flux:callout icon="credit-card" data-test="payment-pending">
                <flux:callout.heading>{{ __('Payment') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('Online payment is not switched on yet. Your seats stay held until :time; after that they are released.', ['time' => $booking->hold_until?->format('H:i')]) }}
                </flux:callout.text>
            </flux:callout>
        @endif
    @endif
</div>
