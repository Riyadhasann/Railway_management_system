<?php

namespace App\Filament\Resources\CoachTypes\Pages;

use App\Filament\Resources\CoachTypes\CoachTypeResource;
use App\Models\CoachType;
use App\Models\Seat;
use App\Services\SeatLayoutService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Click-to-paint seat layout editor for a coach type.
 * Pick a tool, click grid cells, then Save.
 *
 * @phpstan-import-type Layout from SeatLayoutService
 */
class EditSeatLayout extends Page
{
    use InteractsWithRecord;

    public const array TOOLS = [
        'seat' => 'Seat',
        'lower' => 'Lower berth',
        'upper' => 'Upper berth',
        'women' => 'Women only',
        'accessible' => 'Accessible',
        'erase' => 'Erase',
    ];

    protected static string $resource = CoachTypeResource::class;

    protected string $view = 'filament.coach-types.edit-seat-layout';

    /** @var Layout */
    public array $cells = [];

    public string $tool = 'seat';

    public bool $dirty = false;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->authorizeAccess();
        $this->cells = app(SeatLayoutService::class)->load($this->coachType());
    }

    public function hydrate(): void
    {
        $this->authorizeAccess();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(CoachTypeResource::canEdit($this->getRecord()), 403);
    }

    public function getTitle(): string|Htmlable
    {
        return "Seat layout: {$this->coachType()->name}";
    }

    public function coachType(): CoachType
    {
        /** @var CoachType */
        return $this->getRecord();
    }

    public function selectTool(string $tool): void
    {
        if (array_key_exists($tool, self::TOOLS)) {
            $this->tool = $tool;
        }
    }

    /**
     * Apply the current tool to one grid cell.
     */
    public function paint(int $row, int $col): void
    {
        $type = $this->coachType();
        if ($row < 1 || $col < 1 || $row > $type->layout_rows || $col > $type->layout_cols) {
            return;
        }

        $key = SeatLayoutService::key($row, $col);
        $cell = $this->cells[$key] ?? null;

        switch ($this->tool) {
            case 'erase':
                unset($this->cells[$key]);
                break;

            case 'seat':
            case 'lower':
            case 'upper':
                $cell ??= ['seat_no' => app(SeatLayoutService::class)->nextSeatNo($this->cells), 'berth' => null, 'flags' => []];
                $cell['berth'] = $this->tool === 'seat' ? null : $this->tool;
                $this->cells[$key] = $cell;
                break;

            case 'women':
            case 'accessible':
                if ($cell === null) {
                    return;
                }
                $flag = $this->tool === 'women' ? Seat::FLAG_WOMEN : Seat::FLAG_ACCESSIBLE;
                $cell['flags'] = in_array($flag, $cell['flags'], true)
                    ? array_values(array_diff($cell['flags'], [$flag]))
                    : [...$cell['flags'], $flag];
                $this->cells[$key] = $cell;
                break;
        }

        $this->dirty = true;
    }

    protected function getHeaderActions(): array
    {
        $type = $this->coachType();

        return [
            Action::make('save')
                ->label('Save layout')
                ->icon(Heroicon::OutlinedCheck)
                ->action(fn () => $this->save()),

            Action::make('fill')
                ->label('Fill pattern')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('gray')
                ->modalDescription('Replaces the current drawing with seats in every column except the aisles. Nothing is saved until you press Save layout.')
                ->schema([
                    CheckboxList::make('aisles')
                        ->label('Aisle columns')
                        ->options(array_combine(range(1, $type->layout_cols), array_map(fn ($c) => "Column {$c}", range(1, $type->layout_cols))))
                        ->columns(min(5, $type->layout_cols)),
                ])
                ->action(function (array $data) use ($type) {
                    $this->cells = app(SeatLayoutService::class)->fill(
                        $type->layout_rows,
                        $type->layout_cols,
                        array_values(array_map('intval', $data['aisles'] ?? [])),
                        $type->seat_class->hasBerths(),
                    );
                    $this->dirty = true;
                }),

            Action::make('renumber')
                ->label('Renumber')
                ->icon(Heroicon::OutlinedHashtag)
                ->color('gray')
                ->action(function () {
                    $this->cells = app(SeatLayoutService::class)->renumber($this->cells);
                    $this->dirty = true;
                }),

            Action::make('reset')
                ->label('Discard changes')
                ->color('gray')
                ->visible(fn () => $this->dirty)
                ->action(function () {
                    $this->cells = app(SeatLayoutService::class)->load($this->coachType());
                    $this->dirty = false;
                }),

            Action::make('details')
                ->label('Edit details')
                ->color('gray')
                ->url(fn () => CoachTypeResource::getUrl('edit', ['record' => $this->getRecord()])),
        ];
    }

    public function save(): void
    {
        try {
            app(SeatLayoutService::class)->save($this->coachType(), $this->cells);
        } catch (DomainException $e) {
            Notification::make()->title('Layout not saved')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->cells = app(SeatLayoutService::class)->load($this->coachType());
        $this->dirty = false;

        Notification::make()->title('Layout saved: '.count($this->cells).' seats')->success()->send();
    }
}
