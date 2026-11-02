@php
    $run = $this->run();
    $canManage = static::canManage();
    $warnings = $this->warnings();
    $field = 'display:flex; flex-direction:column; gap:.25rem; font-size:.875rem;';
    $list = 'font-size:.8125rem; display:grid; gap:.25rem;';
@endphp

<x-filament-panels::page>
    <style>
        .ca-runs { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .ca-runs th, .ca-runs td { padding: .5rem .6rem; text-align: left; border-bottom: 1px solid rgb(228 228 231 / .7); vertical-align: top; }
        .ca-runs th { font-size: .6875rem; text-transform: uppercase; letter-spacing: .04em; color: rgb(113 113 122); }
        .ca-runs tr.ca-active td { background: rgb(244 244 245 / .6); }
        .ca-row { display: flex; justify-content: space-between; align-items: center; gap: .75rem; padding: .4rem 0; border-bottom: 1px solid rgb(228 228 231 / .7); font-size: .875rem; }
        .ca-wrap { overflow-x: auto; }
    </style>

    @if ($warnings['licences']->isNotEmpty() || $warnings['locos']->isNotEmpty())
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="warning" collapsible data-test="warnings">
            <x-slot name="heading">Coming up: {{ $warnings['licences']->count() }} licences, {{ $warnings['locos']->count() }} locos due for maintenance</x-slot>
            <div style="display:grid; gap:1rem; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));">
                <div style="{{ $list }}">
                    <strong>Driving licences (next {{ \App\Services\CrewService::LICENCE_WARNING_DAYS }} days)</strong>
                    @forelse ($warnings['licences'] as $staff)
                        <span>{{ $staff->label() }} ·
                            @if ($staff->licence_expires_on === null) <span style="color: rgb(220 38 38);">no licence on record</span>
                            @elseif ($staff->licence_expires_on->isPast()) <span style="color: rgb(220 38 38);">expired {{ $staff->licence_expires_on->format('d M Y') }}</span>
                            @else expires {{ $staff->licence_expires_on->format('d M Y') }}
                            @endif
                        </span>
                    @empty
                        <span>None.</span>
                    @endforelse
                </div>
                <div style="{{ $list }}">
                    <strong>Loco maintenance (next {{ \App\Services\CrewService::MAINTENANCE_WARNING_DAYS }} days)</strong>
                    @forelse ($warnings['locos'] as $loco)
                        <span>{{ $loco->label() }} ·
                            @if ($loco->next_maintenance_due->isPast()) <span style="color: rgb(220 38 38);">overdue since {{ $loco->next_maintenance_due->format('d M Y') }}</span>
                            @else due {{ $loco->next_maintenance_due->format('d M Y') }}
                            @endif
                        </span>
                    @empty
                        <span>None.</span>
                    @endforelse
                </div>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section>
        <label style="{{ $field }} max-width:14rem;">
            Journey date
            <x-filament::input.wrapper>
                <x-filament::input type="date" wire:model.live="date" />
            </x-filament::input.wrapper>
        </label>

        @if ($this->runs->isEmpty())
            <p style="margin-top:1rem; font-size:.875rem;">No trains run on this date.</p>
        @else
            <div class="ca-wrap" style="margin-top:1rem;">
                <table class="ca-runs" data-test="runs">
                    <thead>
                        <tr><th>Train</th><th>Crew</th><th>Loco</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($this->runs as $r)
                            @php $problems = $this->problems($r); @endphp
                            <tr wire:key="run-{{ $r->id }}" @class(['ca-active' => $r->id === $runId])>
                                <td><strong>{{ $r->train->label() }}</strong></td>
                                <td>{{ $r->crewAssignments->count() }}</td>
                                <td>{{ $r->locoAssignment?->locomotive->loco_no ?? '—' }}</td>
                                <td>
                                    @if ($problems === [])
                                        <x-filament::badge color="success">Ready</x-filament::badge>
                                    @else
                                        <x-filament::badge color="warning" :tooltip="implode(' ', $problems)">{{ count($problems) }} to fix</x-filament::badge>
                                    @endif
                                </td>
                                <td style="text-align:right;">
                                    <x-filament::button size="xs" color="gray" wire:click="selectRun({{ $r->id }})">{{ $canManage ? 'Staff it' : 'View' }}</x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    @if ($run)
        @php $problems = $this->problems($run); @endphp
        <x-filament::section data-test="run-panel">
            <x-slot name="heading">{{ $run->train->label() }} · {{ $run->journey_date->format('D d M Y') }}</x-slot>

            @if ($problems !== [])
                <ul style="margin:0 0 1rem; padding-left:1.1rem; font-size:.875rem; color: rgb(180 83 9);" data-test="problems">
                    @foreach ($problems as $problem)
                        <li>{{ $problem }}</li>
                    @endforeach
                </ul>
            @endif

            <div style="display:grid; gap:1.5rem; grid-template-columns: repeat(auto-fit, minmax(18rem, 1fr)); align-items:start;">
                <div>
                    <h3 style="font-weight:600; margin-bottom:.5rem;">Crew</h3>
                    @forelse ($this->crew() as $a)
                        <div class="ca-row" wire:key="crew-{{ $a->id }}">
                            <span>{{ $a->staff->label() }} <span style="opacity:.65;">· {{ $a->duty->getLabel() }}</span></span>
                            @if ($canManage)
                                <x-filament::button size="xs" color="gray" wire:click="removeCrew({{ $a->id }})">Remove</x-filament::button>
                            @endif
                        </div>
                    @empty
                        <p style="font-size:.875rem;">Nobody assigned yet.</p>
                    @endforelse

                    @if ($canManage)
                        <div style="display:flex; gap:.5rem; align-items:flex-end; margin-top:.75rem;">
                            <label style="{{ $field }} flex:1;">
                                Add crew member
                                <x-filament::input.wrapper>
                                    <x-filament::input.select wire:model="staffId">
                                        <option value="">Choose</option>
                                        @foreach ($this->staffOptions() as $group => $options)
                                            <optgroup label="{{ $group }}">
                                                @foreach ($options as $id => $label)
                                                    <option value="{{ $id }}">{{ $label }}</option>
                                                @endforeach
                                            </optgroup>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            </label>
                            <x-filament::button wire:click="addCrew" icon="heroicon-o-plus">Add</x-filament::button>
                        </div>
                    @endif
                </div>

                <div>
                    <h3 style="font-weight:600; margin-bottom:.5rem;">Locomotive</h3>
                    @if ($run->locoAssignment)
                        <div class="ca-row">
                            <span>{{ $run->locoAssignment->locomotive->label() }}
                                @if ($run->locoAssignment->locomotive->status !== \App\Enums\LocoStatus::Available)
                                    <x-filament::badge color="danger" style="display:inline-flex;">{{ $run->locoAssignment->locomotive->status->getLabel() }}</x-filament::badge>
                                @endif
                            </span>
                            @if ($canManage)
                                <x-filament::button size="xs" color="gray" wire:click="removeLoco">Remove</x-filament::button>
                            @endif
                        </div>
                    @else
                        <p style="font-size:.875rem;">No loco assigned yet.</p>
                    @endif

                    @if ($canManage)
                        <div style="display:flex; gap:.5rem; align-items:flex-end; margin-top:.75rem;">
                            <label style="{{ $field }} flex:1;">
                                {{ $run->locoAssignment ? 'Replace with' : 'Assign loco' }}
                                <x-filament::input.wrapper>
                                    <x-filament::input.select wire:model="locoId">
                                        <option value="">Choose</option>
                                        @foreach ($this->locoOptions() as $id => $label)
                                            <option value="{{ $id }}">{{ $label }}</option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            </label>
                            <x-filament::button wire:click="assignLoco" icon="heroicon-o-check">Assign</x-filament::button>
                        </div>
                    @endif
                </div>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
