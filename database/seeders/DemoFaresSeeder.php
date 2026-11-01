<?php

namespace Database\Seeders;

use App\Enums\SeatClass;
use App\Models\Concession;
use App\Models\FareRule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo fares: 50 km bands up to 400 km for every class, plus two concessions.
 * Sample numbers for testing only. Safe to re-run: classes that already have rules are skipped.
 */
class DemoFaresSeeder extends Seeder
{
    private const int BAND_KM = 50;

    private const int MAX_KM = 400;

    /** Approximate taka per km, used to price each band at its upper end. */
    private const array TAKA_PER_KM = [
        'ac_berth' => 3.7,
        'ac_chair' => 2.5,
        'snigdha' => 2.1,
        'shovan_chair' => 1.1,
    ];

    /** Fare never goes below this, in taka. */
    private const int MIN_FARE_TAKA = 30;

    /** code => [name, percent, min age, max age] */
    private const array CONCESSIONS = [
        'CHILD' => ['Child', 50, null, 11],
        'SENIOR' => ['Senior citizen', 25, 65, null],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            foreach (SeatClass::cases() as $class) {
                if (FareRule::where('seat_class', $class->value)->exists()) {
                    continue;
                }

                for ($from = 0; $from < self::MAX_KM; $from += self::BAND_KM) {
                    $to = $from + self::BAND_KM;
                    // Round to the nearest 5 taka.
                    $taka = max(self::MIN_FARE_TAKA, (int) (round(self::TAKA_PER_KM[$class->value] * $to / 5) * 5));

                    FareRule::create([
                        'seat_class' => $class,
                        'km_from' => $from === 0 ? 0 : $from + 1,
                        'km_to' => $to,
                        'fare_poisha' => $taka * 100,
                    ]);
                }
            }

            foreach (self::CONCESSIONS as $code => [$name, $percent, $minAge, $maxAge]) {
                Concession::firstOrCreate(
                    ['code' => $code],
                    ['name' => $name, 'percent' => $percent, 'min_age' => $minAge, 'max_age' => $maxAge, 'is_active' => true],
                );
            }
        });

        $this->command?->info(sprintf(
            'Demo fares: %d fare rules, %d concessions.',
            FareRule::count(), Concession::count(),
        ));
    }
}
