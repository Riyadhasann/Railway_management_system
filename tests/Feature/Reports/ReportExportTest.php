<?php

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Filament\Pages\Reports;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Services\ReportExporter;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-05 12:00'));
    $booking = Booking::factory()->confirmed()->create();
    Payment::create(['booking_id' => $booking->id, 'method' => PaymentMethod::Cash, 'reference' => 'CSHEXPORT', 'amount_poisha' => 125050, 'status' => PaymentStatus::Success, 'paid_at' => now()]);
    $this->exporter = app(ReportExporter::class);
});

it('is open to admin, finance and manager only', function (Role $role, int $status) {
    $this->actingAs(tap(User::factory()->create())->assignRole($role->value))
        ->get('/admin/reports')
        ->assertStatus($status);
})->with([
    'admin' => [Role::Admin, 200],
    'finance' => [Role::Finance, 200],
    'manager' => [Role::Manager, 200],
    'counter clerk' => [Role::CounterClerk, 403],
]);

it('writes one report as CSV with money in taka', function () {
    $path = $this->exporter->csv('daily_revenue', now()->subDay(), now());
    $content = (string) file_get_contents($path);
    unlink($path);
    $lines = array_map(fn ($l) => str_getcsv($l, escape: ''), explode("\n", trim(substr($content, 3))));

    // A UTF-8 byte-order mark first, so Excel opens Bangla text correctly.
    expect(substr($content, 0, 3))->toBe("\xEF\xBB\xBF")->and($lines)->toBe([
        ['Date', 'Payments (Tk)', 'Refunds (Tk)', 'Net (Tk)'],
        ['2026-11-04', '0', '0', '0'],
        ['2026-11-05', '1250.5', '0', '1250.5'],
    ]);
});

it('writes every report as a sheet of one Excel workbook', function () {
    $path = $this->exporter->xlsx(now()->subDay(), now());
    $reader = new Reader;
    $reader->open($path);
    $sheets = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        $rows = [];
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
        $sheets[$sheet->getName()] = $rows;
    }

    $reader->close();
    unlink($path);

    expect(array_keys($sheets))->toBe(array_values(ReportExporter::REPORTS))
        ->and($sheets['Daily revenue'][1])->toBe(['Date', 'Payments (Tk)', 'Refunds (Tk)', 'Net (Tk)'])
        ->and($sheets['Daily revenue'][3])->toBe(['2026-11-05', 1250.5, 0, 1250.5])
        ->and($sheets['Revenue by channel'][2])->toBe(['Online', 1250.5, 0, 1250.5]);
});

it('previews the chosen report and downloads it from the page', function () {
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Finance->value));

    Livewire::test(Reports::class)
        ->set('from', '2026-11-05')
        ->assertSee('1,250.50')
        ->call('downloadCsv')
        ->assertFileDownloaded('daily-revenue-2026-11-05-to-2026-11-05.csv')
        ->call('downloadExcel')
        ->assertFileDownloaded('railway-reports-2026-11-05-to-2026-11-05.xlsx')
        ->set('report', 'occupancy')
        ->assertSee('Occupancy by run');
});

it('rejects a range that runs backwards', function () {
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Finance->value));

    Livewire::test(Reports::class)
        ->set('from', '2026-11-06')
        ->assertSee('Choose a start date on or before the end date')
        ->call('downloadExcel')
        ->assertNoFileDownloaded()
        ->assertNotified('Choose a valid date range.');
});
