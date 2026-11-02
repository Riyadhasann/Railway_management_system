@php
    use App\Support\Money;
    $run = $this->run();
    $stops = $this->stops;
    $rows = $this->chart();
    $checked = $this->checked();
    $fines = $this->fines();
    $field = 'display:flex; flex-direction:column; gap:.25rem; font-size:.875rem;';
    $error = 'color: rgb(220 38 38); font-size:.75rem;';
@endphp

<x-filament-panels::page>
    <style>
        .pc-table { width: 100%; border-collapse: collapse; font-size: .8125rem; }
        .pc-table th, .pc-table td { padding: .45rem .6rem; text-align: left; border-bottom: 1px solid rgb(228 228 231 / .7); vertical-align: middle; }
        .pc-table th { font-size: .6875rem; text-transform: uppercase; letter-spacing: .04em; color: rgb(113 113 122); }
        .pc-wrap { overflow-x: auto; }
        .pc-noshow td { opacity: .55; text-decoration: line-through; }
        .pc-noshow td.pc-keep { text-decoration: none; opacity: 1; }
        .pc-print-head { display: none; }
        @media print {
            .fi-sidebar, .fi-topbar, .fi-header, .no-print { display: none !important; }
            .fi-main { padding: 0 !important; max-width: none !important; }
            .pc-print-head { display: block; margin-bottom: .75rem; }
            .pc-table { font-size: 10pt; }
            .pc-noshow td { opacity: 1; }
        }
    </style>

    <x-filament::section class="no-print">
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
                <x-filament::button color="gray" icon="heroicon-o-printer" x-on:click="window.print()">Print chart</x-filament::button>
            @endif
        </div>
    </x-filament::section>

    @if ($run)
        <div class="pc-print-head">
            <strong style="font-size:14pt;">Passenger chart · {{ $run->train->label() }}</strong><br>
            {{ $run->journey_date->format('l d F Y') }} · printed {{ now()->format('d M Y H:i') }}
        </div>

        <x-filament::section>
            <x-slot name="heading">{{ $rows->count() }} passengers · {{ count($checked) }} bookings checked · {{ $rows->filter->isNoShow()->count() }} no-show</x-slot>

            @if ($rows->isEmpty())
                <p style="font-size:.875rem;">No tickets have been sold for this train.</p>
            @else
                <div class="pc-wrap">
                    <table class="pc-table" data-test="chart">
                        <thead>
                            <tr>
                                <th>Seat</th>
                                <th>Passenger</th>
                                <th>Age / sex</th>
                                <th>PNR</th>
                                <th>From → to</th>
                                <th>Checked</th>
                                <th class="no-print"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $p)
                                <tr wire:key="p-{{ $p->id }}" @class(['pc-noshow' => $p->isNoShow()])>
                                    <td><strong>{{ $p->seatBooking->coach_no }}-{{ $p->seatBooking->seat->seat_no }}</strong></td>
                                    <td>{{ $p->name }}@if ($p->concession) <span style="opacity:.7;">({{ $p->concession->name }})</span>@endif</td>
                                    <td>{{ $p->age }} / {{ $p->gender->getLabel() }}</td>
                                    <td style="letter-spacing:.05em;">{{ $p->booking->pnr }}</td>
                                    <td>{{ $stops[$p->booking->from_seq] ?? '' }} → {{ $stops[$p->booking->to_seq] ?? '' }}</td>
                                    <td class="pc-keep">
                                        @if ($p->isNoShow())
                                            <x-filament::badge color="danger">No-show</x-filament::badge>
                                        @elseif (isset($checked[$p->booking_id]))
                                            <x-filament::badge color="success">Checked</x-filament::badge>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="no-print pc-keep" style="text-align:right;">
                                        <x-filament::button size="xs" color="gray" wire:click="toggleNoShow({{ $p->id }})">
                                            {{ $p->isNoShow() ? 'Undo no-show' : 'Mark no-show' }}
                                        </x-filament::button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Fines ({{ $fines->count() }}, {{ Money::format((int) $fines->sum('amount_poisha')) }})</x-slot>

            <form wire:submit="recordFine" class="no-print" style="display:grid; gap:.75rem; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); align-items:start; margin-bottom:1rem;">
                <label style="{{ $field }}">
                    Passenger name
                    <x-filament::input.wrapper :valid="! $errors->has('fine.passenger_name')"><x-filament::input type="text" wire:model="fine.passenger_name" /></x-filament::input.wrapper>
                    @error('fine.passenger_name') <span style="{{ $error }}">{{ $message }}</span> @enderror
                </label>
                <label style="{{ $field }}">
                    Reason
                    <x-filament::input.wrapper :valid="! $errors->has('fine.reason')">
                        <x-filament::input.select wire:model="fine.reason">
                            <option value="">Choose</option>
                            @foreach ($this->fineReasons() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    @error('fine.reason') <span style="{{ $error }}">{{ $message }}</span> @enderror
                </label>
                <label style="{{ $field }}">
                    Amount (৳)
                    <x-filament::input.wrapper :valid="! $errors->has('fine.amount')"><x-filament::input type="number" min="1" step="1" inputmode="numeric" wire:model="fine.amount" /></x-filament::input.wrapper>
                    @error('fine.amount') <span style="{{ $error }}">{{ $message }}</span> @enderror
                </label>
                <label style="{{ $field }}">
                    PNR (if any)
                    <x-filament::input.wrapper :valid="! $errors->has('fine.pnr')"><x-filament::input type="text" wire:model="fine.pnr" maxlength="10" style="text-transform:uppercase;" /></x-filament::input.wrapper>
                    @error('fine.pnr') <span style="{{ $error }}">{{ $message }}</span> @enderror
                </label>
                <label style="{{ $field }}">
                    Note
                    <x-filament::input.wrapper><x-filament::input type="text" wire:model="fine.note" /></x-filament::input.wrapper>
                </label>
                <div style="align-self:end;">
                    <x-filament::button type="submit" icon="heroicon-o-banknotes">Record fine</x-filament::button>
                </div>
            </form>

            @if ($fines->isNotEmpty())
                <div class="pc-wrap">
                    <table class="pc-table" data-test="fines">
                        <thead>
                            <tr><th>Receipt</th><th>Passenger</th><th>Reason</th><th>Amount</th><th>PNR</th><th>By</th><th>Time</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($fines as $f)
                                <tr>
                                    <td>{{ $f->receipt_no }}</td>
                                    <td>{{ $f->passenger_name }}@if ($f->note) <span style="opacity:.7;">({{ $f->note }})</span>@endif</td>
                                    <td>{{ $f->reason->getLabel() }}</td>
                                    <td>{{ Money::format($f->amount_poisha) }}</td>
                                    <td>{{ $f->booking?->pnr ?? '—' }}</td>
                                    <td>{{ $f->tte->name }}</td>
                                    <td>{{ $f->created_at?->format('H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
