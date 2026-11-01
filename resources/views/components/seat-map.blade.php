{{--
    Read-only seat map for one coach. Used by the back-office run seat map and,
    later, the passenger portal.

    Props:
      coach      App\Data\CoachMap
      selectable bool    when true, seats are buttons calling $clickMethod(coachNo, seatId)
      selected   list<string> keys "coachNo|seatId" to outline
      clickMethod string  Livewire method name (default "toggleSeat")
      onlyAvailable bool  when selectable, only available seats can be clicked
--}}
@props([
    'coach',
    'selectable' => false,
    'selected' => [],
    'clickMethod' => 'toggleSeat',
    'onlyAvailable' => false,
])

@php
    use App\Enums\SeatState;
    use App\Models\Seat;
    $stateClass = fn (SeatState $s) => match ($s) {
        SeatState::Available => 'sg-seat',
        SeatState::Held => 'sg-held',
        SeatState::Booked => 'sg-booked',
        SeatState::Blocked => 'sg-blocked',
    };
@endphp

<x-seat-grid-styles />

<div class="sg-coach" wire:key="coach-{{ $coach->coachNo }}">
    <div class="sg-coach-head">
        <span><strong>{{ $coach->coachNo }}</strong> · {{ $coach->coachTypeName }}</span>
        <span class="sg-muted">{{ $coach->availableCount() }} / {{ count($coach->seats) }} free</span>
    </div>
    <div class="sg-grid" style="grid-template-columns: repeat({{ $coach->cols }}, 2.6rem);" role="grid" aria-label="Coach {{ $coach->coachNo }}">
        @for ($r = 1; $r <= $coach->rows; $r++)
            @for ($c = 1; $c <= $coach->cols; $c++)
                @php $seat = $coach->at($r, $c); @endphp
                @if (! $seat)
                    <span class="sg-cell sg-aisle" aria-hidden="true"></span>
                @else
                    @php
                        $key = "{$coach->coachNo}|{$seat->seatId}";
                        $clickable = $selectable && (! $onlyAvailable || $seat->state === SeatState::Available);
                        $classes = 'sg-cell ' . $stateClass($seat->state) . (in_array($key, $selected, true) ? ' sg-selected' : '');
                        $mark = ($seat->hasFlag(Seat::FLAG_WOMEN) ? 'W' : '') . ($seat->hasFlag(Seat::FLAG_ACCESSIBLE) ? '♿' : '');
                        $label = "Seat {$coach->coachNo}-{$seat->seatNo}, {$seat->state->getLabel()}";
                    @endphp
                    @if ($clickable)
                        <button type="button" class="{{ $classes }}" wire:click="{{ $clickMethod }}('{{ $coach->coachNo }}', {{ $seat->seatId }})"
                                aria-label="{{ $label }}" aria-pressed="{{ in_array($key, $selected, true) ? 'true' : 'false' }}">
                    @else
                        <span class="{{ $classes }}" aria-label="{{ $label }}" title="{{ $label }}">
                    @endif
                        <span>{{ $seat->seatNo }}</span>
                        @if ($seat->berth)<span class="sg-berth">{{ $seat->berth === 'lower' ? 'L' : 'U' }}</span>@endif
                        @if ($mark !== '')<span class="sg-flag">{{ $mark }}</span>@endif
                    @if ($clickable) </button> @else </span> @endif
                @endif
            @endfor
        @endfor
    </div>
</div>
