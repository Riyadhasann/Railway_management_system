@php
    $run = $this->run();
    $recorded = $this->recorded();
    $field = 'display:flex; flex-direction:column; gap:.25rem; font-size:.875rem;';
@endphp

<x-filament-panels::page>
    <style>
        .at-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .at-table th, .at-table td { padding: .5rem .6rem; text-align: left; border-bottom: 1px solid rgb(228 228 231 / .7); vertical-align: top; }
        .at-table th { font-size: .6875rem; text-transform: uppercase; letter-spacing: .04em; color: rgb(113 113 122); }
        .at-wrap { overflow-x: auto; }
        .at-time { width: 7.5rem; }
    </style>

    <x-filament::section>
        <div style="display:flex; flex-wrap:wrap; gap:1rem; align-items:flex-end; justify-content:space-between;">
            <label style="{{ $field }} min-width:16rem; flex:1;">
                Train
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="runId">
                        <option value="">Choose a train</option>
                        @foreach ($this->runs as $r)
                            <option value="{{ $r->id }}">{{ $r->train->label() }} · {{ $r->journey_date->format('D d M') }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
            @if ($run)
                <div style="display:flex; gap:.5rem; align-items:center;" data-test="progress">
                    <x-filament::badge :color="$run->delay_minutes > 0 ? 'warning' : 'success'">{{ $run->status->getLabel() }}</x-filament::badge>
                    <span style="font-size:.875rem;">{{ $run->progressLabel() ?? 'No actual times yet' }}</span>
                </div>
            @endif
        </div>
    </x-filament::section>

    @if ($run)
        <x-filament::section>
            <x-slot name="description">Type times as 24-hour HH:MM. A time just after midnight counts as the next day when the train was due late the night before.</x-slot>
            <div class="at-wrap">
                <table class="at-table">
                    <thead>
                        <tr>
                            <th>Station</th>
                            <th>Scheduled</th>
                            <th>Actual arrival</th>
                            <th>Actual departure</th>
                            <th>Late</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->stops as $stop)
                            @php
                                $done = $recorded->get($stop->id);
                                $arr = $stop->arrivalOn($run->journey_date);
                                $dep = $stop->departureOn($run->journey_date);
                            @endphp
                            <tr wire:key="stop-{{ $stop->id }}">
                                <td><strong>{{ $stop->routeStop->station->name }}</strong></td>
                                <td style="white-space:nowrap;">
                                    {{ $arr?->format('H:i') ?? '—' }} / {{ $dep?->format('H:i') ?? '—' }}
                                    @if ($stop->day_offset > 0) <span style="opacity:.6;">+{{ $stop->day_offset }}d</span>@endif
                                </td>
                                <td>
                                    @if ($arr)
                                        <x-filament::input.wrapper class="at-time"><x-filament::input type="time" wire:model="times.{{ $stop->id }}.arrival" /></x-filament::input.wrapper>
                                    @else — @endif
                                </td>
                                <td>
                                    @if ($dep)
                                        <x-filament::input.wrapper class="at-time"><x-filament::input type="time" wire:model="times.{{ $stop->id }}.departure" /></x-filament::input.wrapper>
                                    @else — @endif
                                    @error("times.{$stop->id}") <div style="color: rgb(220 38 38); font-size:.75rem; margin-top:.25rem;">{{ $message }}</div> @enderror
                                </td>
                                <td>
                                    @if ($done)
                                        <x-filament::badge :color="$done->delay_minutes > 0 ? 'warning' : 'success'">{{ $done->delay_minutes > 0 ? $done->delay_minutes.' min' : 'On time' }}</x-filament::badge>
                                        <div style="font-size:.6875rem; opacity:.6; margin-top:.25rem;">{{ $done->recorder?->name }}</div>
                                    @endif
                                </td>
                                <td style="text-align:right;">
                                    <x-filament::button size="sm" wire:click="save({{ $stop->id }})">Save</x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
