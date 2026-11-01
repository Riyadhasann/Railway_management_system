<?php

namespace App\Livewire\Booking;

use App\Data\TrainOption;
use App\Models\Station;
use App\Services\TrainSearchService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Passenger portal step 1: pick stations and a travel date, see trains with free seats and fares.
 *
 * @property-read Collection<int, Station> $stations
 * @property-read list<TrainOption> $results
 */
#[Title('Book tickets')]
class SearchTrains extends Component
{
    #[Url]
    public ?int $from = null;

    #[Url]
    public ?int $to = null;

    #[Url]
    public string $date = '';

    public bool $searched = false;

    public function mount(): void
    {
        if ($this->date === '') {
            $this->date = $this->minDate();
        }

        $this->searched = $this->from !== null && $this->to !== null;
    }

    public function search(): void
    {
        $this->validate([
            'from' => ['required', 'integer', 'exists:stations,id'],
            'to' => ['required', 'integer', 'exists:stations,id', 'different:from'],
            'date' => ['required', 'date', 'after_or_equal:'.$this->minDate(), 'before_or_equal:'.$this->maxDate()],
        ], [
            'to.different' => 'Choose a destination different from the starting station.',
            'date.after_or_equal' => 'Choose today or a later date.',
            'date.before_or_equal' => 'Tickets are sold up to '.config('railway.runs_ahead_days').' days ahead.',
        ]);

        $this->searched = true;
        unset($this->results);
    }

    public function swap(): void
    {
        [$this->from, $this->to] = [$this->to, $this->from];
        $this->searched = false;
    }

    /**
     * @return Collection<int, Station>
     */
    #[Computed]
    public function stations(): Collection
    {
        return Station::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']);
    }

    /**
     * @return list<TrainOption>
     */
    #[Computed]
    public function results(): array
    {
        if (! $this->searched || $this->from === null || $this->to === null || $this->getErrorBag()->isNotEmpty()) {
            return [];
        }

        $from = Station::find($this->from);
        $to = Station::find($this->to);

        if ($from === null || $to === null) {
            return [];
        }

        return app(TrainSearchService::class)->search($from, $to, CarbonImmutable::parse($this->date));
    }

    public function minDate(): string
    {
        return now()->toDateString();
    }

    public function maxDate(): string
    {
        return now()->addDays((int) config('railway.runs_ahead_days'))->toDateString();
    }

    public function render(): View
    {
        return view('livewire.booking.search-trains');
    }
}
