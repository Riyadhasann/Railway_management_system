@php
    $stops = $this->stops;
    $total = $this->selectionTotal();
    $departure = app(\App\Services\SeatInventoryService::class)->boardingDeparture($run, $from);
@endphp

<div class="mx-auto w-full max-w-6xl space-y-6" wire:poll.5s="refreshMap">
    <x-seat-grid-styles />

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ $run->train->label() }}</flux:heading>
            <flux:text class="mt-1">
                {{ $stops[$from] ?? '' }} → {{ $stops[$to] ?? '' }}
                @if ($departure) · {{ __('departs') }} {{ $departure->format('D d M, H:i') }} @endif
            </flux:text>
        </div>
        <flux:button :href="route('booking.search')" variant="ghost" icon="arrow-left" wire:navigate>{{ __('Back to search') }}</flux:button>
    </div>

    <div class="sg-legend">
        <span><span class="sg-cell sg-seat"></span>{{ __('Free') }}</span>
        <span><span class="sg-cell sg-seat sg-selected"></span>{{ __('Your pick') }}</span>
        <span><span class="sg-cell sg-held"></span>{{ __('On hold') }}</span>
        <span><span class="sg-cell sg-booked"></span>{{ __('Sold') }}</span>
        <span><span class="sg-cell sg-blocked"></span>{{ __('Not for sale online') }}</span>
        <span class="sg-muted">{{ __('W = women only, ♿ = accessible. The map refreshes every few seconds.') }}</span>
    </div>

    @if ($notice)
        <flux:callout variant="warning" icon="exclamation-triangle">
            <flux:callout.text>{{ $notice }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($run->isCancelled())
        <flux:callout variant="danger" icon="x-circle">
            <flux:callout.text>{{ __('This train run has been cancelled.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="sg-wrap">
            @foreach ($this->coaches as $coach)
                <x-seat-map :coach="$coach" :selectable="true" :only-available="true" :selected="$selected" />
            @endforeach
        </div>
    @endif

    <div class="sticky bottom-0 flex flex-wrap items-center justify-between gap-4 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
        <div>
            <div class="font-medium">
                {{ trans_choice(':count seat selected|:count seats selected', count($selected)) }}
                <span class="text-zinc-500">({{ __('max :max', ['max' => $this->maxSeats()]) }})</span>
            </div>
            <div class="text-sm text-zinc-500 dark:text-zinc-400">
                @if ($selected !== [])
                    {{ collect($selected)->map(fn ($k) => explode('|', $k)[0])->countBy()->map(fn ($n, $c) => "$c × $n")->join(', ') }}
                    · {{ $total === null ? __('No fare set for this class') : __('Total').' '.\App\Support\Money::format($total) }}
                @else
                    {{ __('Tap free seats to pick them.') }}
                @endif
            </div>
        </div>
        <flux:button wire:click="hold" variant="primary" icon="lock-closed" :disabled="$selected === [] || $total === null" data-test="hold-button">
            {{ __('Hold seats for :min minutes', ['min' => config('railway.hold_minutes')]) }}
        </flux:button>
    </div>
</div>
