@php
    use App\Support\Money;
    $stops = $this->stops;
    $coaches = $this->coaches;
    $total = $this->baseTotal();
    $last = $this->lastBooking();
    $field = 'display:flex; flex-direction:column; gap:.25rem; font-size:.875rem;';
    $error = 'color: rgb(220 38 38); font-size:.75rem;';
@endphp

<x-filament-panels::page>
    <x-seat-grid-styles />

    <x-filament::section>
        <div style="display:flex; flex-wrap:wrap; gap:1rem; align-items:flex-end;">
            <label style="{{ $field }}">
                Travel date
                <x-filament::input.wrapper>
                    <x-filament::input type="date" wire:model.live="date" />
                </x-filament::input.wrapper>
            </label>
            <label style="{{ $field }} min-width:16rem;">
                Train
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="runId">
                        <option value="">Choose a train</option>
                        @foreach ($this->runs as $run)
                            <option value="{{ $run->id }}">{{ $run->train->label() }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
            @if ($stops !== [])
                <label style="{{ $field }}">
                    From
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="fromSeq">
                            @foreach (array_slice($stops, 0, -1, true) as $seq => $name)
                                <option value="{{ $seq }}">{{ $name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                <label style="{{ $field }}">
                    To
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="toSeq">
                            @foreach (array_slice($stops, 1, null, true) as $seq => $name)
                                <option value="{{ $seq }}">{{ $name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
            @endif
        </div>
        @if ($this->runs->isEmpty())
            <p class="sg-muted" style="margin-top:.75rem;">No scheduled trains on this date.</p>
        @endif
    </x-filament::section>

    @if ($last)
        <x-filament::section>
            <x-slot name="heading">Last sale: PNR {{ $last->pnr }}</x-slot>
            <p style="font-size:.875rem;">
                {{ $last->passengers->map(fn ($p) => $p->name.' ('.$p->seatBooking->coach_no.'-'.$p->seatBooking->seat->seat_no.', '.Money::format($p->fare_poisha).')')->join(', ') }}
                · <strong>Total {{ Money::format($last->total_poisha) }}</strong>
            </p>
            <div style="margin-top:.75rem;">
                <x-filament::button tag="a" :href="route('tickets.pdf', $last)" icon="heroicon-o-printer" color="gray" target="_blank">
                    Print ticket (PDF)
                </x-filament::button>
            </div>
        </x-filament::section>
    @endif

    @if ($this->run() && $this->fromSeq < $this->toSeq)
        <div wire:poll.5s="refreshMap">
            <div class="sg-legend" style="margin-bottom:.75rem;">
                <span><span class="sg-cell sg-seat"></span>Free</span>
                <span><span class="sg-cell sg-seat sg-selected"></span>Selected</span>
                <span><span class="sg-cell sg-held"></span>On hold</span>
                <span><span class="sg-cell sg-booked"></span>Sold</span>
                <span><span class="sg-cell sg-blocked"></span>Blocked</span>
            </div>
            <div class="sg-wrap">
                @foreach ($coaches as $coach)
                    <x-seat-map :coach="$coach" :selectable="true" :only-available="true" :selected="$selected" />
                @endforeach
            </div>
        </div>

        @if ($selected !== [])
            <x-filament::section>
                <x-slot name="heading">Passengers ({{ count($selected) }})</x-slot>

                <div style="display:flex; flex-direction:column; gap:1rem;">
                    @foreach ($selected as $key)
                        <div wire:key="pax-{{ $key }}" style="display:grid; grid-template-columns: 5rem 2fr 5rem 8rem 1.5fr 1.5fr; gap:.75rem; align-items:start;">
                            <div style="padding-top:1.6rem; font-weight:600;">{{ $this->seatLabel($key) }}</div>
                            <label style="{{ $field }}">Name
                                <x-filament::input.wrapper><x-filament::input type="text" wire:model="passengers.{{ $key }}.name" /></x-filament::input.wrapper>
                                @error("passengers.{$key}.name") <span style="{{ $error }}">{{ $message }}</span> @enderror
                            </label>
                            <label style="{{ $field }}">Age
                                <x-filament::input.wrapper><x-filament::input type="number" min="0" max="120" wire:model="passengers.{{ $key }}.age" /></x-filament::input.wrapper>
                                @error("passengers.{$key}.age") <span style="{{ $error }}">{{ $message }}</span> @enderror
                            </label>
                            <label style="{{ $field }}">Gender
                                <x-filament::input.wrapper>
                                    <x-filament::input.select wire:model="passengers.{{ $key }}.gender">
                                        <option value="">Choose</option>
                                        @foreach ($this->genders() as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                                @error("passengers.{$key}.gender") <span style="{{ $error }}">{{ $message }}</span> @enderror
                            </label>
                            <label style="{{ $field }}">NID (18+)
                                <x-filament::input.wrapper><x-filament::input type="text" wire:model="passengers.{{ $key }}.nid" /></x-filament::input.wrapper>
                                @error("passengers.{$key}.nid") <span style="{{ $error }}">{{ $message }}</span> @enderror
                            </label>
                            <label style="{{ $field }}">Concession
                                <x-filament::input.wrapper>
                                    <x-filament::input.select wire:model="passengers.{{ $key }}.concession_id">
                                        <option value="">None</option>
                                        @foreach ($this->concessions as $concession)
                                            <option value="{{ $concession->id }}">{{ $concession->label() }}</option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                                @error("passengers.{$key}.concession_id") <span style="{{ $error }}">{{ $message }}</span> @enderror
                            </label>
                        </div>
                    @endforeach

                    <div style="display:flex; flex-wrap:wrap; gap:1rem; align-items:flex-end; justify-content:space-between;">
                        <label style="{{ $field }}">Mobile (optional)
                            <x-filament::input.wrapper><x-filament::input type="text" wire:model="phone" placeholder="01712345678" /></x-filament::input.wrapper>
                            @error('phone') <span style="{{ $error }}">{{ $message }}</span> @enderror
                        </label>
                        <div style="display:flex; gap:1rem; align-items:center;">
                            <span style="font-size:.875rem;">
                                Base fare: <strong>{{ $total === null ? 'no fare rule for this class' : Money::format($total) }}</strong>
                                <span class="sg-muted">(concessions applied on sale)</span>
                            </span>
                            <x-filament::button wire:click="sell" icon="heroicon-o-banknotes" :disabled="$total === null">
                                Sell for cash
                            </x-filament::button>
                        </div>
                    </div>
                </div>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
