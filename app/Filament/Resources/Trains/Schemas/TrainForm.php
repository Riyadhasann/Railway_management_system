<?php

namespace App\Filament\Resources\Trains\Schemas;

use App\Enums\SeatBookingStatus;
use App\Models\CoachType;
use App\Models\RouteStop;
use App\Models\Seat;
use App\Models\SeatBooking;
use App\Models\Train;
use App\Models\TrainRoute;
use App\Services\TimetableValidator;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class TrainForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Train')
                    ->columns(2)
                    ->schema([
                        TextInput::make('number')
                            ->required()
                            ->maxLength(10)
                            ->unique(ignoreRecord: true)
                            ->helperText('e.g. 701'),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Select::make('train_route_id')
                            ->label('Route')
                            ->options(fn () => TrainRoute::query()->orderBy('code')->pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(fn (Set $set, mixed $state) => $set('scheduleStops', self::blankTimetable($state)))
                            ->helperText('Changing the route resets the timetable below.'),
                        Toggle::make('is_active')->label('Active')->default(true)->inline(false),
                        CheckboxList::make('running_days')
                            ->label('Runs on (departure day from origin)')
                            ->options(Train::WEEKDAYS)
                            ->default(array_keys(Train::WEEKDAYS))
                            ->columns(7)
                            ->required()
                            ->columnSpanFull(),
                    ]),

                Section::make('Coaches')
                    ->description('Coach order from the engine. Counter quota = seats kept for counter sales; the rest are sold online.')
                    ->collapsible()
                    ->schema([
                        Repeater::make('rakeCoaches')
                            ->hiddenLabel()
                            ->relationship('rakeCoaches')
                            ->orderColumn('position')
                            ->reorderable()
                            ->defaultItems(0)
                            ->addActionLabel('Add coach')
                            ->columns(3)
                            ->schema([
                                TextInput::make('coach_no')
                                    ->label('Coach')
                                    ->required()
                                    ->maxLength(10)
                                    ->distinct()
                                    ->dehydrateStateUsing(fn (?string $state) => strtoupper(trim((string) $state)))
                                    ->placeholder('e.g. KA'),
                                Select::make('coach_type_id')
                                    ->label('Coach type')
                                    ->options(fn () => CoachType::query()->where('is_active', true)->withCount('seats')->orderBy('code')->get()
                                        ->mapWithKeys(fn (CoachType $t) => [$t->id => "{$t->label()} · {$t->seats_count} seats"]))
                                    ->required()
                                    ->live(),
                                TextInput::make('counter_quota')
                                    ->label('Counter quota')
                                    ->numeric()->integer()->minValue(0)->default(0)->required()
                                    ->maxValue(fn (Get $get) => self::seatCount($get('coach_type_id')))
                                    ->helperText(fn (Get $get) => filled($get('coach_type_id')) ? 'Max '.self::seatCount($get('coach_type_id')) : null),
                            ])
                            ->rules([
                                fn (?Train $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record) {
                                    foreach (self::rakeErrors($record, is_array($value) ? $value : []) as $error) {
                                        $fail($error);
                                    }
                                },
                            ]),
                    ]),

                Section::make('Timetable')
                    ->description('Leave arrival empty at the first stop and departure empty at the last. Use day offset 1 for times after midnight on overnight trains.')
                    ->schema([
                        Repeater::make('scheduleStops')
                            ->hiddenLabel()
                            ->relationship('scheduleStops')
                            ->reorderable(false)
                            ->addActionLabel('Add stop')
                            ->columns(4)
                            ->schema([
                                Select::make('route_stop_id')
                                    ->label('Station')
                                    ->options(fn (Get $get) => self::routeStopOptions($get('../../train_route_id')))
                                    ->required()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                                TimePicker::make('arrival_time')->label('Arrives')->seconds(false),
                                TimePicker::make('departure_time')->label('Departs')->seconds(false),
                                TextInput::make('day_offset')
                                    ->label('Day offset')
                                    ->numeric()
                                    ->integer()
                                    ->minValue(0)
                                    ->maxValue(3)
                                    ->default(0)
                                    ->required(),
                            ])
                            ->rules([
                                fn (TimetableValidator $validator): Closure => function (string $attribute, mixed $value, Closure $fail) use ($validator) {
                                    foreach ($validator->scheduleErrors(self::rowsForValidation($value)) as $error) {
                                        $fail($error);
                                    }
                                },
                            ]),
                    ]),
            ]);
    }

    /**
     * Coaches that already have live seat bookings or blocks must stay, with the same coach type.
     *
     * @param  array<array-key, mixed>  $rows
     * @return list<string>
     */
    public static function rakeErrors(?Train $train, array $rows): array
    {
        if ($train === null) {
            return [];
        }

        $submitted = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $submitted[strtoupper(trim((string) ($row['coach_no'] ?? '')))] = (int) ($row['coach_type_id'] ?? 0);
            }
        }

        $inUse = SeatBooking::query()
            ->whereIn('train_run_id', $train->runs()->select('id'))
            ->where('status', '!=', SeatBookingStatus::Cancelled->value)
            ->distinct()
            ->pluck('coach_no');

        $current = $train->rakeCoaches()->pluck('coach_type_id', 'coach_no');
        $errors = [];

        foreach ($inUse as $coachNo) {
            if (! array_key_exists($coachNo, $submitted)) {
                $errors[] = "Coach {$coachNo} has bookings or blocked seats and can't be removed.";
            } elseif (isset($current[$coachNo]) && $submitted[$coachNo] !== (int) $current[$coachNo]) {
                $errors[] = "Coach {$coachNo} has bookings or blocked seats, so its coach type can't change.";
            }
        }

        return $errors;
    }

    public static function seatCount(mixed $coachTypeId): int
    {
        return blank($coachTypeId) ? 0 : Seat::where('coach_type_id', $coachTypeId)->count();
    }

    /**
     * @return array<int, string>
     */
    public static function routeStopOptions(mixed $routeId): array
    {
        if (blank($routeId)) {
            return [];
        }

        return RouteStop::query()
            ->with('station')
            ->where('train_route_id', $routeId)
            ->orderBy('sequence')
            ->get()
            ->mapWithKeys(fn (RouteStop $s) => [$s->id => "{$s->station->name} ({$s->distance_km} km)"])
            ->all();
    }

    /**
     * One empty timetable row per station on the route.
     *
     * @return array<string, array{route_stop_id: int, arrival_time: null, departure_time: null, day_offset: int}>
     */
    public static function blankTimetable(mixed $routeId): array
    {
        if (blank($routeId)) {
            return [];
        }

        return RouteStop::query()
            ->where('train_route_id', $routeId)
            ->orderBy('sequence')
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [(string) Str::uuid() => [
                'route_stop_id' => $id,
                'arrival_time' => null,
                'departure_time' => null,
                'day_offset' => 0,
            ]])
            ->all();
    }

    /**
     * @return list<array{sequence: int, arrival_time: ?string, departure_time: ?string, day_offset: mixed}>
     */
    private static function rowsForValidation(mixed $value): array
    {
        $rows = array_values(is_array($value) ? $value : []);
        $sequences = RouteStop::query()
            ->whereIn('id', array_filter(array_column($rows, 'route_stop_id')))
            ->pluck('sequence', 'id');

        return array_map(fn (array $row) => [
            'sequence' => (int) ($sequences[$row['route_stop_id'] ?? 0] ?? 0),
            'arrival_time' => $row['arrival_time'] ?? null,
            'departure_time' => $row['departure_time'] ?? null,
            'day_offset' => $row['day_offset'] ?? 0,
        ], $rows);
    }
}
