<div class="mx-auto w-full max-w-4xl space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Book tickets') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Choose where you are travelling and when.') }}</flux:text>
    </div>

    <form wire:submit="search" class="grid gap-4 rounded-xl border border-zinc-200 p-4 sm:grid-cols-[1fr_auto_1fr_auto_auto] sm:items-end dark:border-zinc-700">
        <flux:select wire:model="from" :label="__('From')" :placeholder="__('Station')">
            @foreach ($this->stations as $station)
                <flux:select.option :value="$station->id">{{ $station->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:button type="button" wire:click="swap" icon="arrows-right-left" variant="ghost" :aria-label="__('Swap stations')" />

        <flux:select wire:model="to" :label="__('To')" :placeholder="__('Station')">
            @foreach ($this->stations as $station)
                <flux:select.option :value="$station->id">{{ $station->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input type="date" wire:model="date" :label="__('Travel date')" :min="$this->minDate()" :max="$this->maxDate()" />

        <flux:button type="submit" variant="primary" icon="magnifying-glass">{{ __('Search') }}</flux:button>
    </form>

    @if ($searched && $errors->isEmpty())
        @php $results = $this->results; @endphp

        @if ($results === [])
            <flux:callout icon="information-circle">
                <flux:callout.text>{{ __('No trains run between these stations on that date.') }}</flux:callout.text>
            </flux:callout>
        @else
            <div class="space-y-4" data-test="search-results">
                @foreach ($results as $option)
                    <div wire:key="run-{{ $option->run->id }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <flux:heading size="lg">{{ $option->run->train->label() }}</flux:heading>
                                <flux:text>
                                    {{ $option->departure->format('D d M, H:i') }} → {{ $option->arrival->format('H:i') }}
                                    @if (! $option->arrival->isSameDay($option->departure))
                                        <span class="text-xs">(+{{ $option->departure->startOfDay()->diffInDays($option->arrival->startOfDay()) }}d)</span>
                                    @endif
                                    · {{ $option->durationLabel() }} · {{ $option->distanceKm }} km
                                </flux:text>
                            </div>

                            <div class="flex items-center gap-2">
                                @if ($option->isCancelled())
                                    <flux:badge color="red">{{ __('Cancelled') }}</flux:badge>
                                @elseif ($option->hasDeparted())
                                    <flux:badge color="zinc">{{ __('Departed') }}</flux:badge>
                                @elseif ($option->availableSeats() === 0)
                                    <flux:badge color="amber">{{ __('Sold out') }}</flux:badge>
                                @endif
                            </div>
                        </div>

                        <div class="mt-3 grid gap-2 sm:grid-cols-4">
                            @foreach ($option->classes as $class)
                                <div class="rounded-lg bg-zinc-50 px-3 py-2 text-sm dark:bg-zinc-900">
                                    <div class="font-medium">{{ $class['class']->getLabel() }}</div>
                                    <div class="text-zinc-500 dark:text-zinc-400">
                                        {{ $class['fare'] !== null ? \App\Support\Money::format($class['fare']) : __('No fare') }}
                                        · {{ trans_choice(':count seat free|:count seats free', $class['available']) }}
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</div>
