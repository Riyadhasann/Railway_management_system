# Railway ERP (prototype)

Laravel 13 + Filament 5 + Livewire 4. See [docs/railway-erp-prototype-plan.md](docs/railway-erp-prototype-plan.md) and [docs/railway-erp-architecture.md](docs/railway-erp-architecture.md).

## Local setup

1. Start any MySQL 8 server matching `.env` (default `127.0.0.1:3307`). On the original dev PC a portable
   MySQL 8.4 lives outside this repo in `../tools/mysql` (port 3307 so it doesn't clash with XAMPP on 3306):
   `powershell -ExecutionPolicy Bypass -File ..\tools\start-mysql.ps1`
   (stop it with `..\tools\stop-mysql.ps1`). Credentials: db `railway_erp`, user `root`, password `secret`.
2. `composer install && npm install`
3. `cp .env.example .env && php artisan key:generate` (first time only)
4. `php artisan migrate --seed` — prints one demo account per role and saves them to
   `storage/app/private/demo-credentials.txt`. Set `DEMO_PASSWORD` in `.env` for a fixed password.
5. `composer run dev` — app at http://localhost:8000, back-office at http://localhost:8000/admin.
   Emails are written to `storage/logs/laravel.log` (`MAIL_MAILER=log`).

## Booking

- **Passengers:** log in as `passenger@railway-erp.test` and open **Book tickets** (`/book`): search →
  pick up to 4 seats (live map) → checkout with a 10-minute hold, passenger details, NID check and a phone
  code shown on screen. Payment is not switched on yet, so bookings stay "pending payment" until the hold ends.
- **Counter:** `counter_clerk@railway-erp.test` → **Bookings → Counter booking** sells seats for cash in one step.
  The last `counter_quota` seats of each coach are counter-only.
- **Fares:** admin edits **Fares → Fare rules / Concessions**; finance and manager can view them.
- **Expired holds** stop blocking seats the moment they run out. `holds:release` tidies them up every minute;
  run `php artisan schedule:work` in a second terminal to see that happen locally.
- **Concurrency check:** `php artisan booking:race` fires 20 parallel holds at one free seat of the next run
  and checks that exactly one wins (MySQL only; the test booking is released afterwards).

## Checks

- `php artisan test` — Pest (in-memory SQLite locally; MySQL in CI). To run on MySQL locally, use the
  `railway_erp_test` database: `$env:DB_CONNECTION='mysql'; $env:DB_PORT='3307'; $env:DB_DATABASE='railway_erp_test'; $env:DB_PASSWORD='secret'; php artisan test`
  The `Concurrency` suite (20 parallel holds) only runs on MySQL; on SQLite it is skipped.
- `composer lint` — Pint
- `composer types:check` — PHPStan / Larastan level 7

## Roles

admin, planner, counter_clerk, station_master, tte, crew_controller, finance, manager, passenger.
Demo logins are `<role>@railway-erp.test`. Staff roles can open `/admin`; passengers use the portal only.
Self-registration always creates a passenger.
