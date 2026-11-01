@php
    $stops = $this->stops;
    $coaches = $this->coaches;
    $totals = $this->totals();
    $canBlock = $this->canBlock();
@endphp

<x-filament-panels::page>
    <x-seat-grid-styles />

    <x-filament::section>
        <div style="display:flex; flex-wrap:wrap; gap:1rem; align-items:flex-end;">
            <label style="display:flex; flex-direction:column; gap:.25rem; font-size:.875rem;">
                From
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="fromSeq">
                        @foreach (array_slice($stops, 0, -1, true) as $seq => $name)
                            <option value="{{ $seq }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
            <label style="display:flex; flex-direction:column; gap:.25rem; font-size:.875rem;">
                To
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="toSeq">
                        @foreach (array_slice($stops, 1, null, true) as $seq => $name)
                            <option value="{{ $seq }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>

            <div class="sg-legend" style="margin-left:auto">
                <span><span class="sg-cell sg-seat"></span>Available {{ $totals['available'] }}</span>
                <span><span class="sg-cell sg-held"></span>Held {{ $totals['held'] }}</span>
                <span><span class="sg-cell sg-booked"></span>Booked {{ $totals['booked'] }}</span>
                <span><span class="sg-cell sg-blocked"></span>Blocked {{ $totals['blocked'] }}</span>
            </div>
        </div>

        @if ($this->run()->isCancelled())
            <p style="margin-top:.75rem; color: rgb(220 38 38); font-size:.875rem;">This run is cancelled.</p>
        @elseif ($canBlock)
            <p class="sg-muted" style="margin-top:.75rem;">Click seats to select them, then use Block / Unblock above. Blocks apply to the selected journey segment.</p>
        @endif
    </x-filament::section>

    @if ($this->fromSeq >= $this->toSeq)
        <x-filament::section>
            <p>Choose a destination after the starting station.</p>
        </x-filament::section>
    @elseif ($coaches === [])
        <x-filament::section>
            <p>This train has no coaches yet. Add them under <strong>Trains → Edit → Coaches</strong>.</p>
        </x-filament::section>
    @else
        <div class="sg-wrap">
            @foreach ($coaches as $coach)
                <x-seat-map :coach="$coach" :selectable="$canBlock" :selected="$this->selected" />
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
