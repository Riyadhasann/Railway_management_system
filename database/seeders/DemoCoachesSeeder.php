<?php

namespace Database\Seeders;

use App\Enums\SeatClass;
use App\Models\CoachType;
use App\Models\RakeCoach;
use App\Models\Seat;
use App\Models\Train;
use App\Services\SeatLayoutService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo coach types (4 classes with seat layouts) and a 6-coach rake for every
 * demo train. Run after DemoNetworkSeeder. Safe to re-run.
 */
class DemoCoachesSeeder extends Seeder
{
    /** code => [name, class, rows, cols, aisle columns] */
    private const array TYPES = [
        'AC_B' => ['AC berth', SeatClass::AcBerth, 6, 5, [3]],          // 24 berths, L/U pairs
        'AC_S' => ['AC chair', SeatClass::AcChair, 10, 5, [3]],         // 40 seats, 2+2
        'SNIGDHA' => ['Snigdha', SeatClass::Snigdha, 12, 5, [3]],       // 48 seats, 2+2
        'S_CHAIR' => ['Shovan chair', SeatClass::ShovanChair, 14, 6, [4]], // 70 seats, 3+2
    ];

    /** coach_no => [coach type code, counter quota] */
    private const array RAKE = [
        'KA' => ['AC_B', 4],
        'KHA' => ['AC_S', 8],
        'GA' => ['SNIGDHA', 10],
        'GHA' => ['SNIGDHA', 10],
        'CHA' => ['S_CHAIR', 20],
        'JA' => ['S_CHAIR', 20],
    ];

    public function run(SeatLayoutService $layouts): void
    {
        DB::transaction(function () use ($layouts) {
            foreach (self::TYPES as $code => [$name, $class, $rows, $cols, $aisles]) {
                $type = CoachType::firstOrCreate(
                    ['code' => $code],
                    ['name' => $name, 'seat_class' => $class, 'layout_rows' => $rows, 'layout_cols' => $cols, 'is_active' => true],
                );

                if (! $type->seats()->exists()) {
                    $cells = $layouts->fill($rows, $cols, $aisles, $class->hasBerths());

                    // First two seats of each Shovan chair coach are women-only.
                    if ($class === SeatClass::ShovanChair) {
                        foreach (array_slice($cells, 0, 2, true) as $key => $cell) {
                            $cells[$key] = ['seat_no' => $cell['seat_no'], 'berth' => $cell['berth'], 'flags' => [Seat::FLAG_WOMEN]];
                        }
                    }

                    $layouts->save($type, $cells);
                }
            }

            $types = CoachType::whereIn('code', array_keys(self::TYPES))->get()->keyBy('code');

            foreach (Train::all() as $train) {
                if ($train->rakeCoaches()->exists()) {
                    continue;
                }

                $position = 1;
                foreach (self::RAKE as $coachNo => [$typeCode, $quota]) {
                    RakeCoach::create([
                        'train_id' => $train->id,
                        'coach_no' => $coachNo,
                        'coach_type_id' => $types[$typeCode]->id,
                        'position' => $position++,
                        'counter_quota' => $quota,
                    ]);
                }
            }
        });

        $this->command?->info(sprintf(
            'Demo coaches: %d coach types, %d seats, %d train coaches.',
            CoachType::count(), Seat::count(), RakeCoach::count(),
        ));
    }
}
