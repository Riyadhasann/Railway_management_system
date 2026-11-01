# Railway ERP — Free Prototype Plan

Nov 1, 2026

## Approach

Build one working web app, at zero cost, in about 6 weeks with 1–2 developers. It includes all 9 modules, so every flow can be clicked and tested end to end. Only things that cost money or need official contracts are simulated.

**What the prototype proves**

- The full journey works: set up a train → publish a schedule → book a seat → pay → get a QR ticket → TTE scans it → revenue shows in reports.
- No seat can be sold twice, even when two people book the same seat at the same moment.
- The screens make sense to real railway staff and passengers (collect feedback before the full build).

**What is simulated (not real)**

- **Payments:** a fake "bKash / Nagad / Card" checkout page with Success and Fail buttons, or a gateway's free sandbox.
- **SMS and OTP:** the code is shown on screen and logged instead of being sent by SMS.
- **NID check:** format check only, no government lookup.
- **Live train position:** station masters type actual times; no GPS feed.
- **Scale:** built for tens of testers, not festival-rush traffic.

**One app, four views.** Instead of separate apps, a single responsive web app shows a different menu per role: passenger portal, back-office, management dashboard, and a mobile-friendly TTE scanner page (works on any phone browser with a camera).

## Free tech stack

Everything below is open-source or has a free tier, so the prototype costs nothing to build or to share with testers.

| Layer | Free choice (Laravel) | Notes |
| --- | --- | --- |
| Framework | Laravel (latest version), PHP 8.3+ | One codebase for back-office, passenger portal, TTE page and API |
| Back-office (staff) | Filament admin panel | Ready-made CRUD screens, tables, filters, forms and dashboards for trains, stations, coaches, staff, locos, reports |
| Passenger portal | Blade + Livewire + Alpine.js + Tailwind CSS | Search, live seat map (refreshed with `wire:poll` every few seconds, or Laravel Reverb for push), checkout countdown without writing a separate JS app |
| Login and roles | Laravel Livewire starter kit + `spatie/laravel-permission` | Roles: admin, planner, counter clerk, station master, TTE, crew controller, finance, manager, passenger |
| Database | MySQL 8 or PostgreSQL | Needs row locking for seat holds, so don't use SQLite for testing concurrency |
| Local server | Laragon (Windows), Laravel Herd (Mac/Windows) or Laravel Sail (Docker) | All free |
| QR tickets | `simplesoftwareio/simple-qrcode` (generate) + `html5-qrcode` JS (scan with phone camera) | TTE scanner is a mobile web page |
| PDF e-ticket | `barryvdh/laravel-dompdf` | Ticket PDF with QR |
| Excel export | `maatwebsite/excel` | Revenue and occupancy exports |
| Charts | Filament chart widgets (Chart.js) | Revenue, occupancy, punctuality |
| Audit log | `spatie/laravel-activitylog` | Who changed what, shown in a Filament page |
| Background jobs | Laravel queue (database driver) + task scheduler | Release expired seat holds, generate daily train runs, process refunds |
| Payments | Mock gateway controller (or SSLCommerz / bKash sandbox) | No real money moves |
| Email | Mailpit locally, or Gmail SMTP | Send e-tickets to testers |
| Code | GitHub (free) | Version control |

**Sharing with testers for free:** run the app on one PC and share it with a free tunnel (Cloudflare Tunnel or ngrok), or put it on the office LAN. Phone browsers only allow camera access over HTTPS, so the TTE scanner needs the tunnel's HTTPS URL (or a local HTTPS certificate). It won't work over plain `http://` on the LAN. When you need a permanent link, a small paid VPS or shared PHP hosting is the cheapest next step.

Package versions change; install the current release of each with Composer and check its docs.

## Laravel build guide

Filament builds most back-office screens for you, so most custom code goes into the passenger booking flow and a few service classes.

### Project setup

```bash
composer global require laravel/installer
laravel new railway-erp          # choose the Livewire starter kit
cd railway-erp
```

Edit `.env` before migrating. Laravel defaults to SQLite, which can't test seat locking:

```env
DB_CONNECTION=mysql              # or pgsql
DB_DATABASE=railway_erp
APP_TIMEZONE=Asia/Dhaka          # refund rules and scheduled jobs depend on local time
```

```bash
composer require filament/filament spatie/laravel-permission spatie/laravel-activitylog barryvdh/laravel-dompdf simplesoftwareio/simple-qrcode maatwebsite/excel
php artisan filament:install --panels
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-migrations"
php artisan migrate --seed
php artisan make:filament-user
php artisan serve
```

simple-qrcode needs the PHP Imagick extension to make PNG QR codes, and dompdf's SVG support is limited. Enable Imagick in Laragon, or use `endroid/qr-code` (works with GD) for the PDF ticket.

### Models and migrations

| Module | Models |
| --- | --- |
| Trains and routes | `Train`, `TrainRoute`, `RouteStop` (station_id, sequence, distance_km) — named `TrainRoute` to avoid clashing with Laravel's `Route` facade |
| Stations and schedules | `Station`, `ScheduleStop` (arr, dep, day_offset), `TrainRun` (train_id, journey_date, status, delay_minutes) |
| Coaches and seats | `CoachType`, `Seat` (coach_type_id, seat_no, berth, flags), `RakeCoach` (train_id, coach_no, coach_type_id, position), `Quota` (train_id, coach_no, channel: online/counter, seat_count) |
| Booking | `Booking` (pnr unique, user_id, train_run_id, from_seq, to_seq, total, status, hold_until), `Passenger`, `SeatBooking` (booking_id nullable, train_run_id, coach_no, seat_id, from_seq, to_seq, status: held/booked/blocked/cancelled, hold_until) |
| Fares | `FareRule` (class, km_from, km_to, fare), `Concession` |
| Payments | `Payment` (booking_id, method, txn_id, amount, status), `Refund`, `RefundRule` (hours_before, percent, fee) |
| Checking | `TicketScan` (booking_id, tte_id, result, scanned_at), `Fine` |
| Staff and locos | `Staff`, `CrewAssignment`, `Locomotive`, `LocoAssignment`, `Maintenance` |

### Folder layout

- `app/Filament/Resources` — one resource per back-office screen (Train, Station, CoachType, Staff, Locomotive, FareRule, RefundRule…).
- `app/Filament/Widgets` — revenue, occupancy and punctuality charts.
- `app/Livewire` — passenger pages: `SearchTrains`, `SeatMap`, `Checkout`, `MyBookings`, plus `TteScanner`.
- `app/Services` — business logic kept out of controllers: `FareService`, `SeatInventoryService`, `PaymentService`, `RefundService`, `TicketService`.
- `app/Console/Commands` — `holds:release`, `runs:generate`.
- `database/seeders` — the demo network, staff, locos and 30 days of past bookings.

### The seat-hold logic (most important code)

Two testers must never get the same seat. Lock the train run inside a database transaction, then check for overlapping segments. A physical seat is identified by **coach_no + seat_id**, because `Seat` is a row in a coach-type layout and the same `seat_id` appears in every coach of that type.

```php
/** @param array<int, array{coach_no: string, seat_id: int}> $seats */
public function hold(TrainRun $run, array $seats, int $fromSeq, int $toSeq): Booking
{
    return DB::transaction(function () use ($run, $seats, $fromSeq, $toSeq) {
        // Lock this train run: a second request for the same run waits here.
        // Every writer (online, counter, admin blocking, payment callback) must take this lock.
        TrainRun::whereKey($run->id)->lockForUpdate()->first();

        $holdUntil = now()->addMinutes(10);

        $taken = SeatBooking::where('train_run_id', $run->id)
            ->where(function ($q) use ($seats) {
                foreach ($seats as $s) {
                    $q->orWhere(fn ($q) => $q->where('coach_no', $s['coach_no'])
                        ->where('seat_id', $s['seat_id']));
                }
            })
            ->where('from_seq', '<', $toSeq)   // segments overlap
            ->where('to_seq', '>', $fromSeq)
            ->where(fn ($q) => $q->whereIn('status', ['booked', 'blocked'])
                ->orWhere(fn ($q) => $q->where('status', 'held')
                    ->where('hold_until', '>', now())))
            ->exists();

        if ($taken) {
            throw new SeatUnavailableException();
        }

        $booking = Booking::create([
            'pnr' => app(TicketService::class)->newPnr(), // unique index; retries on collision; no 0/O/1/I
            'user_id' => auth()->id(),
            'train_run_id' => $run->id,
            'from_seq' => $fromSeq,
            'to_seq' => $toSeq,
            'total' => app(FareService::class)->quote($run, $seats, $fromSeq, $toSeq),
            'status' => 'pending_payment',
            'hold_until' => $holdUntil,
        ]);

        foreach ($seats as $s) {
            $booking->seatBookings()->create([
                'train_run_id' => $run->id,
                'coach_no' => $s['coach_no'],
                'seat_id' => $s['seat_id'],
                'from_seq' => $fromSeq,
                'to_seq' => $toSeq,
                'status' => 'held',
                'hold_until' => $holdUntil,
            ]);
        }

        return $booking;
    });
}
```

Admin seat blocking writes a `SeatBooking` row with `status = 'blocked'` and no booking, so the same check covers it.

### Scheduled jobs

In `routes/console.php`:

```php
Schedule::command('holds:release')->everyMinute();      // expire unpaid holds
Schedule::command('runs:generate')->dailyAt('01:00');   // create train runs for the next 10 days
```

Run `php artisan schedule:work` and `php artisan queue:work` while testing.

### Mock payment flow

1. `Checkout` sends the passenger to `/mock-pay/{booking}`, a page styled as bKash / Nagad / Card with Success, Fail and Cancel buttons.
2. The button posts to `PaymentController@callback`, which calls `PaymentService` exactly as a real gateway callback would.
3. Success → booking `confirmed`, seats `booked`, PDF ticket with QR generated. Fail → booking `failed`, seats released.
4. **Late payment:** the callback takes the same `TrainRun` lock and checks `hold_until`. If the hold has expired, it re-checks that the seats are still free. If they are, it confirms. If not, it marks the payment failed and refunds it, so a seat is never sold twice.
5. The QR holds a signed token (HMAC of PNR + train run, using `APP_KEY`), not the bare PNR, so it can't be forged.
6. Later, swap the mock for a real gateway by changing only `PaymentService`.

## Feature scope

All 9 modules are in the prototype with their core screens working against a real database; the right-hand column lists what is simplified.

| Module | Works in the prototype | Simplified or mocked |
| --- | --- | --- |
| 1. Train and route management | Add/edit trains, routes with ordered stations and distances, running days, cancel a train for a date | No timetable versioning |
| 2. Station and schedule management | Station list, arrival/departure per stop, auto-generate daily train runs, enter actual times, delay shown to passengers | No GPS; manual times only |
| 3. Coach and seat configuration | Coach types and classes, seat-layout grid editor, rake composition per train, quotas (online/counter) | One layout per coach type |
| 4. Online ticket reservations | Search, seat-map pick, up to 4 passengers, fare calculation, PNR, PDF e-ticket with QR, booking history, counter-booking screen | OTP shown on screen; NID format check only |
| 5. Seat availability management | Live availability per class, 10-minute seat hold with countdown, auto-release, segment-wise booking (A→B and B→C on one seat), admin seat blocking | Waitlist left out (or one simple queue) |
| 6. Payment and refund processing | Mock checkout (bKash/Nagad/Card), success/fail/timeout paths, refund rules by hours before departure, auto-refund on train cancellation, transaction list | No real gateway, no settlement file |
| 7. Ticket checking and validation | Phone-camera QR scanner for TTE, valid/invalid/already-used/cancelled result, passenger chart per train run, mark no-show, record a fine | Needs network (no offline sync) |
| 8. Staff and locomotive management | Staff list with roles and licence expiry, assign crew and loco to a train run, loco status and maintenance due dates, expiry warnings | No rest-hour rule engine |
| 9. Revenue and operational reports | Dashboard: daily revenue, revenue by train/class/channel, occupancy %, refunds, on-time %; CSV/Excel export | Reports query the main database |

**Shared across all modules:** login with roles (admin, planner, counter clerk, station master, TTE, crew controller, finance, manager, passenger), menu changes per role, and an audit log page showing who changed what.

## Demo data and test accounts

A seed script fills the database with realistic sample data, so testers see a working railway on day one and can reset it with one click.

**Sample network (fictional data for testing)**

- 12–15 stations on 2–3 routes, e.g. a Dhaka–Chattogram style main line and a Dhaka–Rajshahi style line.
- 6 trains with daily runs generated for the next 10 days.
- 4 coach classes (AC berth, AC chair, Snigdha, Shovan chair) with seat layouts.
- 30 staff (drivers, guards, TTEs) and 8 locomotives, two with maintenance due soon.
- Past bookings for the last 30 days, so reports and charts already show numbers.

**Test accounts (one per role)**

| Role | What they test |
| --- | --- |
| Admin | Users, roles, fare rules, refund rules, audit log |
| Operations planner | Trains, routes, schedules, coaches, quotas |
| Counter clerk | Counter booking and cancellation |
| Station master | Enter actual arrival/departure, report delays |
| TTE | Scan tickets, passenger chart, fines |
| Crew controller | Assign crew and locomotives |
| Finance | Payments, refunds, transaction list |
| Manager | Dashboards and reports |
| Passenger | Search, book, pay, cancel, download ticket |

Generate passwords in the seed script and share them privately with testers; a "Reset demo data" button for admins restores the starting state.

## Build schedule

Six weekly steps, each ending in something you can click and show; a single developer may need 8 weeks.

| Week | Build | Demo at the end of the week |
| --- | --- | --- |
| 1 | Laravel + Filament setup, migrations, login and roles, trains, stations, routes, schedules | Planner creates a train and its timetable |
| 2 | Coach types, seat-layout editor, rake composition, daily train runs, seat inventory | A train run shows a full seat map |
| 3 | Search, seat selection, 10-minute hold, fare engine, PNR, counter booking | Two testers try the same seat; only one gets it (plus an automated parallel-request test, since manual clicks rarely collide) |
| 4 | Mock payment, PDF e-ticket with QR, cancellation and refund rules, train cancellation refunds | Book → pay → ticket → cancel → refund |
| 5 | TTE scanner and passenger chart, staff and loco screens, crew/loco assignment, actual-time entry | TTE scans a ticket on a phone |
| 6 | Reports dashboard, exports, audit log, seed data, bug fixing, deploy, tester guide | Full walkthrough with all roles |

Total cost: ৳0 if built in-house on free tiers; the only cost is developer time.

## Test scenarios

Tick each scenario off during the tester walkthrough; together they exercise every module.

**Setup (planner and admin)**

- [ ] Create a new station and add it to a route with its distance.
- [ ] Create a train, set running days, timetable and coach composition.
- [ ] Design a new coach layout and mark two seats as reserved for women.
- [ ] Change a fare rule and confirm new searches show the new price.

**Booking and payment (passenger and counter clerk)**

- [ ] Search a route and date, pick 2 seats, pay successfully, download the ticket.
- [ ] Two people select the same seat at once: only one hold succeeds.
- [ ] Hold a seat and wait 10 minutes: the seat is released automatically.
- [ ] Payment fails: the booking expires and seats return.
- [ ] Book A→B and another passenger books B→C on the same seat.
- [ ] Counter clerk sells a seat; it disappears from the online seat map within a few seconds.
- [ ] Hold expires, someone else takes the seat, then the first person's payment succeeds: the late payment is refunded and the seat is not sold twice.
- [ ] Admin blocks a seat; it can't be booked online or at the counter.

**Refunds**

- [ ] Cancel 3 days before departure and check the refund amount against the rule.
- [ ] Cancel 2 hours before departure: no refund.
- [ ] Planner cancels a train run: all its passengers are refunded automatically.

**Operations (TTE, station master, crew controller)**

- [ ] TTE scans a valid ticket, then the same ticket again (shows already used).
- [ ] TTE scans a cancelled ticket and a ticket for another date (both rejected).
- [ ] Station master enters a 25-minute delay; passengers see it on their booking.
- [ ] Crew controller tries to assign a driver with an expired licence (blocked).
- [ ] Assign a locomotive that is under repair (blocked).

**Reports (finance and manager)**

- [ ] Today's revenue matches the sum of today's successful payments minus refunds.
- [ ] Occupancy chart for a train run matches its seat map.
- [ ] Export the revenue report to Excel.
- [ ] Audit log shows who changed the fare rule.

## From prototype to production

The database design and booking logic carry over; what changes is scale, real integrations and security hardening, as set out in the full project plan.

| Area | Prototype | Production |
| --- | --- | --- |
| Payments | Mock checkout | Real bKash, Nagad, SSLCommerz contracts and reconciliation |
| SMS / OTP | Shown on screen | Paid SMS gateway |
| Identity | NID format check | Official NID verification service |
| TTE app | Phone browser, online only | Offline-first handheld app with sync |
| Scale | Tens of testers | Waiting room, Redis seat locks, autoscaling, load tests |
| Hosting | Free tiers | Paid cloud or government data centre with backups and DR |
| Reports | Main database | Separate reporting store |
| Security | Basic roles | 2FA for staff, encryption, security audit |

Run the prototype with 10–20 real users (counter clerks, a TTE, a station master, passengers) for 1–2 weeks, and turn their feedback into the requirements for Phase 0 of the full plan.
