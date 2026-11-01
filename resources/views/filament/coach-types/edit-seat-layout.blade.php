@php
    use App\Filament\Resources\CoachTypes\Pages\EditSeatLayout;
    use App\Models\Seat;
    $type = $this->coachType();
    $flagMark = fn (array $flags) => (in_array(Seat::FLAG_WOMEN, $flags, true) ? 'W' : '') . (in_array(Seat::FLAG_ACCESSIBLE, $flags, true) ? '♿' : '');
@endphp

<x-filament-panels::page>
    <x-seat-grid-styles />

    <x-filament::section>
        <div style="display:flex; flex-direction:column; gap:1rem;">
            <div class="sg-tools" role="toolbar" aria-label="Layout tools">
                @foreach (EditSeatLayout::TOOLS as $key => $label)
                    <button type="button" class="sg-tool" wire:click="selectTool('{{ $key }}')" aria-pressed="{{ $tool === $key ? 'true' : 'false' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <p class="sg-muted">
                {{ $type->seat_class->getLabel() }} · grid {{ $type->layout_rows }} × {{ $type->layout_cols }} ·
                <strong>{{ count($cells) }}</strong> seats
                @if ($dirty) · <span style="color: rgb(217 119 6)">unsaved changes</span> @endif
                — pick a tool, then click cells. "Fill pattern" draws a full coach in one go.
            </p>

            <div class="sg-coach" style="align-self:flex-start">
                <div class="sg-coach-head"><strong>Front</strong><span class="sg-muted">click to paint</span></div>
                <div class="sg-grid" style="grid-template-columns: repeat({{ $type->layout_cols }}, 2.6rem);">
                    @for ($r = 1; $r <= $type->layout_rows; $r++)
                        @for ($c = 1; $c <= $type->layout_cols; $c++)
                            @php $cell = $cells["{$r}-{$c}"] ?? null; @endphp
                            <button type="button"
                                    wire:key="cell-{{ $r }}-{{ $c }}"
                                    wire:click="paint({{ $r }}, {{ $c }})"
                                    class="sg-cell {{ $cell ? 'sg-seat' : 'sg-empty' }}"
                                    aria-label="Row {{ $r }}, column {{ $c }}{{ $cell ? ', seat ' . $cell['seat_no'] : ', empty' }}">
                                @if ($cell)
                                    <span>{{ $cell['seat_no'] }}</span>
                                    @if ($cell['berth'])<span class="sg-berth">{{ $cell['berth'] === 'lower' ? 'L' : 'U' }}</span>@endif
                                    @if ($flagMark($cell['flags']) !== '')<span class="sg-flag">{{ $flagMark($cell['flags']) }}</span>@endif
                                @endif
                            </button>
                        @endfor
                    @endfor
                </div>
            </div>

            <div class="sg-legend">
                <span><span class="sg-cell sg-seat"></span>Seat</span>
                <span><span class="sg-cell sg-empty"></span>Empty / aisle</span>
                <span><strong>L</strong> / <strong>U</strong> lower / upper berth</span>
                <span><strong>W</strong> women only</span>
                <span><strong>♿</strong> accessible</span>
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
