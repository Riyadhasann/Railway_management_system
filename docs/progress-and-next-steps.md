# Railway ERP: progress and next steps

Snapshot: **Nov 5, 2026**, last commit `a47686c` on `main`.
Read this first when starting a new session. The full design and phase plan are in
[railway-erp-architecture.md](railway-erp-architecture.md). The prototype scope and test scenarios are in
[railway-erp-prototype-plan.md](railway-erp-prototype-plan.md).

## 1. Where things stand

| Phase | Name | Status |
| --- | --- | --- |
| 0 | Foundation | ✅ done |
| 1 | Network and schedules | ✅ done |
| 2 | Coaches and inventory | ✅ done |
| 3 | Booking engine | ✅ done |
| 4 | Payments, tickets, refunds | ✅ done |
| 5 | Operations | 🟡 built; only the real-phone TTE scanner test is left (see §3.4) |
| 6 | Reports and hardening | 🟡 4 of 7 pieces done (see below) |
| 7 | Pilot | not started |
| 8 | Production readiness | not started |

### Phase 6 pieces

| # | Piece | Status | Commit |
| --- | --- | --- | --- |
| 1 | `ReportService`: daily revenue, revenue by train/channel, sales by class, occupancy, refunds, on-time %, tickets sold | ✅ | `457456d` |
| 2 | Dashboard widgets: revenue stats + revenue, channel, class, train and occupancy charts (7/30/90-day filters) | ✅ | `eab7bf2` |
| 3 | **Reports → Reports and export** page: CSV per report, Excel workbook of all (openspout) | ✅ | `9704621` |
| 4 | **Admin → Audit log**: who/when/what with before → after, filters, password fields hidden | ✅ | `dc0c9e2` |
| 5 | `PastBookingsSeeder` (30 days of history) + **Admin → Demo data** reset page + `php artisan demo:reset` | ✅ | `a47686c` |
| 6 | Bug-fix / N+1 / indexes pass + review of `composer audit` advisories | ⏭ **next** | |
| 7 | Tester guide (one page per role) + share the demo over the HTTPS tunnel | to do | |
| 8 | Update Phase 6 in `railway-erp-architecture.md` (tick the boxes, set the status) | to do | |

Piece 5 merged the plan's items "PastBookingsSeeder" and "Reset demo data"; the plan's "maatwebsite/excel" was replaced by openspout.

## 2. Starting a session

```powershell
# 1. Start MySQL (portable 8.4, port 3307; XAMPP MariaDB on 3306 is NOT ours)
F:\Railway_Management_ERP\tools\start-mysql.ps1

# 2. Run the app
cd F:\Railway_Management_ERP\railway-erp
php artisan serve            # http://127.0.0.1:8000  (staff panel: /admin)
npm run build                # only if front-end assets changed

# 3. Tests and checks (run before every commit)
php artisan test                                   # Pest, SQLite in memory
vendor/bin/pint --dirty                            # code style
vendor/bin/phpstan analyse --memory-limit=1G       # static analysis
```

- DB: `railway_erp` (dev) and `railway_erp_test` on 127.0.0.1:3307, root / `secret`.
- Demo accounts: `<role>@railway-erp.test` (admin, planner, counter_clerk, station_master, tte,
  crew_controller, finance, manager, passenger). Passwords are in `storage/app/private/demo-credentials.txt`
  (not committed). `DEMO_PASSWORD` in `.env` is empty, so passwords are random when seeding from scratch.
  `demo:reset` keeps the existing ones.
- No Docker and no `gh` CLI; use plain git. If `git push` hangs, Git Credential Manager is waiting for
  "Select an account" in a window.

## 3. What to do next

### 3.1 Piece 6: bug-fix, N+1 and indexes pass

1. **Booking engine speed.** About 0.3 s per sale (measured while writing the seeder). In
   `SeatInventoryService::hold()` the train's `scheduleStops` and `rakeCoaches.coachType.seats` are loaded
   several times per call: in `assertBookable`, `assertSeatsSellable`, `boardingDeparture`, `FareService::quote`
   and `availability()`. Load them once per request, or pass them along. Do the same in `RefundService::quote`
   and `TrainRunService::updateProgress`.
2. **N+1 check** of the busy pages with the query log or Debugbar: seat selection, counter booking, My
   bookings, passenger chart, scanner, refunds/payments tables, audit log, dashboard widgets.
3. **Indexes** against architecture §2.4. Report queries filter on `payments(status, paid_at)` (exists),
   `refunds(status, processed_at)` (exists), `bookings(train_run_id, status)` (exists). Check `activity_log`
   (subject/causer filters), `seat_bookings(booking_id)` and `train_runs(journey_date)` usage.
4. **`composer audit`**: Composer reported 13 advisories in 6 packages. Update what can be updated within
   the constraints, and note any that can't be fixed.
5. **Look at the dashboard with real history.** The dev DB has no history yet. Run
   `php artisan demo:reset` (it **wipes the dev DB** and takes about 2 minutes on this MySQL; ask the user
   first). Then check the charts, the reports page and the Excel export by eye.

### 3.2 Piece 7: tester guide

One page per role (what to log in as, where to click, which prototype test scenarios that role covers), in
`docs/`. Include how to share over the HTTPS tunnel (needed for the TTE camera).

### 3.3 Piece 8: docs

Tick the Phase 6 checkboxes in `railway-erp-architecture.md` §3, set its status, and mention
`demo:reset` and the openspout export. Update this file.

### 3.4 Still open from Phase 5

Real-phone test of **Checking → Scan tickets** over an HTTPS tunnel (browsers only allow the camera on
HTTPS or localhost). This needs the user's phone.

### 3.5 After Phase 6

Full walkthrough with all roles, and tick every scenario in the prototype plan's "Test scenarios".
Then move to Phase 7 (pilot).

## 4. Prototype test scenarios: automated coverage

All of them have automated tests. They still need to be ticked off in a manual walkthrough (§3.5).

| Area | Tests |
| --- | --- |
| Setup | `tests/Feature/Network`, `tests/Feature/Coaches`, `tests/Feature/Fares` |
| Booking and payment | `tests/Feature/Booking`, `tests/Feature/Payments`, `tests/Concurrency/ParallelHoldsTest.php` |
| Refunds | `tests/Feature/Payments/RefundTest.php` |
| Operations | `tests/Feature/Checking`, `tests/Feature/Operations` |
| Reports | `tests/Feature/Reports` (revenue, occupancy, export, audit log, past bookings, demo reset) |

## 5. Working rules (agreed with the user)

- Work in **small pieces** (about 5–15 files). After each piece, show the files and a proposed message, then
  ask **"Should I commit and push this one?"** Approval for one commit doesn't carry over to the next.
- Commit as **Md. Riyad Hasan**, with **no Claude / Co-Authored-By lines** and **no phase names** in
  commit messages.
- Stage only the files of that piece, then `git push origin main`.
- Every piece passes `php artisan test` (the touched area at least), Pint and PHPStan before it's offered.
- Edit files with the editor tools, not `sed`/inline Python: backslashes in PHP namespaces get mangled.
- Don't wipe or reseed the dev DB (`railway_erp`) without asking. Use a scratch database for experiments.

## 6. Useful facts and gotchas

- Money is integer **poisha** (`App\Support\Money`). The audit log shows raw poisha (10000 = ৳100).
- Revenue is cash basis: successful payments by `paid_at` minus processed refunds by `processed_at`.
  Occupancy = distinct seats with a booked segment ÷ rake seats. On time = arrived ≤ 5 min late.
- All seat changes go through `SeatInventoryService` (train-run row lock). Admins pass only dotted
  permission checks (`Gate::before`), so record-level policies still apply to them.
- `PastBookingsSeeder` writes rows in bulk (no audit-log entries) for speed, and skips itself if past
  bookings exist. Tests use `$seeder->days = 3`.
- Filament widgets that tests must see need `protected static ?bool $isLazyLoaded = false` (`$isLazy` is deprecated). Vendor models (like
  `Activity`) need `Gate::policy()` in `AppServiceProvider`.
- On this machine MySQL syncs every statement (binlog + `innodb_flush_log_at_trx_commit=1`), so wrap bulk
  writes in a transaction.
