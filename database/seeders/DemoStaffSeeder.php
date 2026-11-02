<?php

namespace Database\Seeders;

use App\Enums\LocoStatus;
use App\Enums\MaintenanceType;
use App\Enums\StaffDesignation;
use App\Models\Locomotive;
use App\Models\Maintenance;
use App\Models\Staff;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo crew and locomotives: 30 staff (one driver with an expired licence, one expiring soon)
 * and 8 locos (one under repair, two with maintenance due soon). Sample data for testing only.
 * Run after DemoNetworkSeeder and DemoUsersSeeder. Safe to re-run.
 */
class DemoStaffSeeder extends Seeder
{
    /** designation => how many */
    private const array CREW = [
        'driver' => 10,
        'assistant_driver' => 8,
        'guard' => 6,
        'tte' => 6,
    ];

    private const array NAMES = [
        'Abdul Karim', 'Mizanur Rahman', 'Shahidul Islam', 'Rafiqul Hasan', 'Jahangir Alam', 'Nasir Uddin',
        'Kamrul Hossain', 'Habibur Rahman', 'Anisur Rahman', 'Delwar Hossain', 'Sohel Rana', 'Faruk Ahmed',
        'Mamun Mia', 'Belal Hossain', 'Shafiqul Islam', 'Monirul Islam', 'Ziaur Rahman', 'Aminul Haque',
        'Sharmin Akter', 'Nasrin Sultana', 'Rokeya Begum', 'Taslima Khatun', 'Ruma Akter', 'Farzana Yasmin',
        'Imran Hossain', 'Tanvir Ahmed', 'Sabbir Hossain', 'Rubel Mia', 'Masud Rana', 'Arif Hasan',
    ];

    /** loco_no => [model, home shed, status, last maintenance (days ago), next due (days from now)] */
    private const array LOCOS = [
        '2901' => ['Hyundai Rotem 2900 class', 'Dhaka', LocoStatus::Available, 40, 50],
        '2902' => ['Hyundai Rotem 2900 class', 'Dhaka', LocoStatus::Available, 80, 5],    // due soon
        '2915' => ['Hyundai Rotem 2900 class', 'Pahartali', LocoStatus::Available, 20, 70],
        '3004' => ['MEI 3000 class', 'Dhaka', LocoStatus::Available, 30, 60],
        '3011' => ['MEI 3000 class', 'Pahartali', LocoStatus::Available, 85, 3],          // due soon
        '3020' => ['MEI 3000 class', 'Parbatipur', LocoStatus::UnderRepair, 2, 90],       // in the workshop
        '2611' => ['Bombardier 2600 class', 'Pahartali', LocoStatus::Available, 15, 75],
        '2624' => ['Bombardier 2600 class', 'Parbatipur', LocoStatus::Available, 50, 40],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            $stations = Station::query()->orderBy('id')->pluck('id')->all();
            $n = 0;

            foreach (self::CREW as $designation => $count) {
                $role = StaffDesignation::from($designation);

                for ($i = 1; $i <= $count; $i++) {
                    $staffNo = sprintf('BR%05d', 10001 + $n);
                    $licence = match (true) {
                        ! $role->needsLicence() => null,
                        $role === StaffDesignation::Driver && $i === 1 => now()->subDays(12),  // expired
                        $role === StaffDesignation::Driver && $i === 2 => now()->addDays(20),  // expiring soon
                        default => now()->addMonths(6 + $i),
                    };

                    Staff::firstOrCreate(['staff_no' => $staffNo], [
                        'name' => self::NAMES[$n % count(self::NAMES)],
                        'designation' => $role,
                        'phone' => sprintf('0171%07d', 1000 + $n),
                        'home_station_id' => $stations === [] ? null : $stations[$n % count($stations)],
                        'licence_no' => $licence === null ? null : sprintf('DL-%06d', 500000 + $n),
                        'licence_expires_on' => $licence?->toDateString(),
                    ]);
                    $n++;
                }
            }

            // The demo TTE login is the first TTE on the crew list.
            $tteUser = User::where('email', 'tte@'.config('railway.demo_email_domain'))->first();
            $firstTte = Staff::where('designation', StaffDesignation::Tte->value)->orderBy('staff_no')->first();

            if ($tteUser !== null && $firstTte !== null && ! Staff::where('user_id', $tteUser->id)->exists()) {
                $firstTte->update(['user_id' => $tteUser->id]);
            }

            foreach (self::LOCOS as $no => [$model, $shed, $status, $lastAgo, $dueIn]) {
                $loco = Locomotive::firstOrCreate(['loco_no' => (string) $no], [
                    'model' => $model,
                    'traction' => 'diesel',
                    'home_shed' => $shed,
                    'status' => $status,
                    'last_maintenance_on' => now()->subDays($lastAgo)->toDateString(),
                    'next_maintenance_due' => now()->addDays($dueIn)->toDateString(),
                ]);

                if ($loco->wasRecentlyCreated && $status === LocoStatus::UnderRepair) {
                    Maintenance::create([
                        'locomotive_id' => $loco->id,
                        'type' => MaintenanceType::Breakdown,
                        'started_on' => now()->subDays(2)->toDateString(),
                        'description' => 'Traction motor fault, waiting for parts',
                    ]);
                }
            }
        });
    }
}
