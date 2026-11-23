# Railway ERP: progress and next steps

Snapshot: **Nov 23, 2026**, after commit `0e19756` (tester guide) on `main`.
Read this first when starting a new session. The full design and phase plan are in
[railway-erp-architecture.md](railway-erp-architecture.md). The prototype scope and test scenarios are in
[railway-erp-prototype-plan.md](railway-erp-prototype-plan.md). The guide for testers is
[tester-guide.md](tester-guide.md).

## 1. Where things stand

| Phase | Name | Status |
| --- | --- | --- |
| 0 | Foundation | ✅ done |
| 1 | Network and schedules | ✅ done |
| 2 | Coaches and inventory | ✅ done |
| 3 | Booking engine | ✅ done |
| 4 | Payments, tickets, refunds | ✅ done |
| 5 | Operations | 🟡 built; only the real-phone TTE scanner test is left |
| 6 | Reports and hardening | 🟡 built; the walkthrough with all roles is left |
| 7 | Pilot | not started |
| 8 | Production readiness | not started |

All code for the prototype is written. What's left before the pilot is checking it by hand (§3).

### Phase 6 pieces

| # | Piece | Commits |
| --- | --- | --- |
| 1 | `ReportService`: daily revenue, revenue by train/channel, sales by class, occupancy, refunds, on-time %, tickets sold | `457456d` |
| 2 | Dashboard widgets: revenue stats + revenue, channel, class, train and occupancy charts (7/30/90-day filters) | `eab7bf2` |
| 3 | **Reports → Reports and export**: CSV per report, Excel workbook of all (openspout) | `9704621` |
| 4 | **Admin → Audit log**: who/when/what with before → after, filters, password fields hidden | `dc0c9e2` |
| 5 | `PastBookingsSeeder` (30 days of history) + **Admin → Demo data** + `php artisan demo:reset` | `a47686c` |
| 6 | Performance and hardening: N+1 guard and `SmokeTest`, fewer booking-engine queries, index-friendly date filters (`App\Support\Day`), `created_at` indexes, security updates | `fb6768f`, `21feba2`, `93643b2`, `5a31e04`, `d444c90` |
| 7 | Tester guide + `TRUSTED_PROXIES` for sharing through a tunnel | `8671dcd`, `0e19756` |

Also fixed along the way: `64cfc2f` (a test was overwriting the real demo-credentials file).

## 2. Starting a session

```powershell
# 1. Start MySQL (portable 8.4, port 3307; XAMPP MariaDB on 3306 is NOT ours)
F:\Railway_Management_ERP\tools\start-mysql.ps1

# 2. Run the app
cd F:\Railway_Management_ERP\railway-erp
php artisan serve            # http://127.0.0.1:8000  (staff panel: /admin, passengers: /login then /book)
php artisan schedule:work    # optional: releases holds, generates runs, expiry warnings
php artisan queue:work       # optional: ticket emails (written to the log)
npm run build                # only if front-end assets changed

# 3. Tests and checks (run before every commit)
php artisan test --parallel                        # Pest, SQLite in memory (~411 tests, ~2.5 min)
vendor/bin/pint --dirty                            # code style
vendor/bin/phpstan analyse --memory-limit=1G       # static analysis
```

- DB: `railway_erp` (dev) and `railway_erp_test` on 127.0.0.1:3307, root / `secret`.
- Demo accounts: `<role>@railway-erp.test` (admin, planner, counter_clerk, station_master, tte,
  crew_controller, finance, manager, passenger), all with the password from `DEMO_PASSWORD` in `.env`
  (set locally, not committed; also listed in `storage/app/private/demo-credentials.txt`).
- The dev DB has **no booking history yet**. `php artisan demo:reset` adds a month of it, but wipes
  everything first (about 2 minutes on this MySQL). Ask the user before running it.
- No Docker and no `gh` CLI; use plain git. If `git push` hangs, Git Credential Manager is waiting for
  "Select an account" in a window.
- Running the test suite on MySQL takes about 20 minutes here (it rebuilds every table first). Don't stop a
  MySQL test run halfway: its PHP process keeps going and clashes with the next run.

## 3. What to do next

### 3.1 Look at the demo with history

With the user's OK, run `php artisan demo:reset`, then check by eye: the dashboard charts, the reports page
and Excel export, the audit log, My bookings for `passenger@railway-erp.test`, and the crew and passenger
chart pages on today's busiest train.

### 3.2 Share over HTTPS (not yet tried)

Follow "For the host" in [tester-guide.md](tester-guide.md): `winget install Cloudflare.cloudflared`,
`TRUSTED_PROXIES=127.0.0.1,::1`, `php artisan config:clear`, restart the server, then
`cloudflared tunnel --url http://127.0.0.1:8000`. Check on a phone that pages, Livewire updates, checkout and
the mock payment page all work over `https://`.

### 3.3 Real-phone TTE scanner test (left from Phase 5)

Over the tunnel, on a phone: **Checking → Scan tickets**, scan a ticket PDF's QR code, then the same one
again (already used), a cancelled ticket and a wrong-date ticket. Needs the user's phone.

### 3.4 Walkthrough with all roles (Phase 6 exit)

Go through [tester-guide.md](tester-guide.md) role by role and tick every scenario in the prototype plan's
"Test scenarios". Fix what turns up, in small commits. Then tick Phase 5 and 6 as complete in
`railway-erp-architecture.md`.

### 3.5 Then Phase 7 (pilot)

10–20 real users for 1–2 weeks, a feedback log (GitHub issues labelled `bug`, `ux`, `missing-feature`),
weekly fixes, and the production requirements write-up. Mostly people work rather than code.

## 4. Prototype test scenarios: automated coverage

All of them have automated tests. They still need the manual walkthrough (§3.4).

| Area | Tests |
| --- | --- |
| Setup | `tests/Feature/Network`, `tests/Feature/Coaches`, `tests/Feature/Fares` |
| Booking and payment | `tests/Feature/Booking`, `tests/Feature/Payments`, `tests/Concurrency/ParallelHoldsTest.php` |
| Refunds | `tests/Feature/Payments/RefundTest.php` |
| Operations | `tests/Feature/Checking`, `tests/Feature/Operations` |
| Reports | `tests/Feature/Reports` (revenue, occupancy, export, audit log, past bookings, demo reset) |
| Every page | `tests/Feature/SmokeTest.php` (opens all pages with demo data, max 80 queries each) |

## 5. Working rules (agreed with the user)

- Work in **small pieces** (about 5–15 files). After each piece, show the files and a proposed message, then
  ask **"Should I commit and push this one?"** Approval for one commit doesn't carry over to the next. The
  user may also ask for a piece to be split into several commits.
- Commit as **Md. Riyad Hasan**, with **no Claude / Co-Authored-By lines** and **no phase names** in
  commit messages.
- Stage only the files of that piece, then `git push origin main`.
- Every piece passes `php artisan test` (the touched area at least), Pint and PHPStan before it's offered.
- Don't wipe or reseed the dev DB (`railway_erp`) without asking. Use a scratch database for experiments.
- `docs/` is tracked in git; the user chose to keep it on GitHub.

## 6. Useful facts and gotchas

- Money is integer **poisha** (`App\Support\Money`). The audit log shows raw poisha (10000 = ৳100).
- Revenue is cash basis: successful payments by `paid_at` minus processed refunds by `processed_at`.
  Occupancy = distinct seats with a booked segment ÷ rake seats. On time = arrived ≤ 5 min late.
- All seat changes go through `SeatInventoryService` (train-run row lock). Admins pass only dotted
  permission checks (`Gate::before`), so record-level policies still apply to them.
- **Date filters:** never `whereDate()` on big tables (MySQL can't use the index through `DATE()`). Use
  `where(col, '>=', Day::start($d))` and `where(col, '<', Day::after($d))`. Custom query-builder macros were
  tried and dropped: Larastan loses the model type through them.
- **N+1:** lazy loading throws in tests and is logged locally. Eager-load relations for lists, and add new
  pages to `SmokeTest`.
- `PastBookingsSeeder` writes rows in bulk (no audit-log entries) for speed, and skips itself if past
  bookings exist. Tests set `$seeder->days` to 1–3.
- Tests that run `DemoUsersSeeder` must call `Storage::fake('local')`, or they overwrite the real
  demo-credentials file.
- Filament widgets that tests must see need `protected static ?bool $isLazyLoaded = false` (`$isLazy` is
  deprecated). Vendor models (like `Activity`) need `Gate::policy()` in `AppServiceProvider`.
- On this machine MySQL syncs every statement (binlog + `innodb_flush_log_at_trx_commit=1`), so wrap bulk
  writes in a transaction.
