# Railway ERP: tester guide

Thank you for testing the Railway ERP prototype. This guide has one page per role. Pick your role, follow its
steps, and tick off the test scenarios at the end of the page. Everything is sample data, so nothing you do
affects a real railway, real money or real passengers.

- [Before you start](#before-you-start) (everyone)
- Roles: [Passenger](#passenger) · [Counter clerk](#counter-clerk) · [Operations planner](#operations-planner) ·
  [Station master](#station-master) · [TTE](#tte-ticket-examiner) · [Crew controller](#crew-controller) ·
  [Finance](#finance) · [Manager](#manager) · [Admin](#admin)
- [Reporting a problem](#reporting-a-problem)
- [For the host: running and sharing the demo](#for-the-host-running-and-sharing-the-demo)

---

## Before you start

**Where:** the host sends you a link. Below it is written as `<demo>`, for example
`https://example.trycloudflare.com` (the link changes each time the host restarts sharing). On the host's own
PC it is `http://127.0.0.1:8000`.

| Role | Email | Log in at |
| --- | --- | --- |
| Passenger | passenger@railway-erp.test | `<demo>/login` |
| Counter clerk | counter_clerk@railway-erp.test | `<demo>/admin` |
| Operations planner | planner@railway-erp.test | `<demo>/admin` |
| Station master | station_master@railway-erp.test | `<demo>/admin` |
| TTE | tte@railway-erp.test | `<demo>/admin` |
| Crew controller | crew_controller@railway-erp.test | `<demo>/admin` |
| Finance | finance@railway-erp.test | `<demo>/admin` |
| Manager | manager@railway-erp.test | `<demo>/admin` |
| Admin | admin@railway-erp.test | `<demo>/admin` |

The host gives you the password; every demo account uses the same one. Staff log in at `/admin`; passengers
use the passenger site (`/login`, then **Book tickets**). You can also register your own passenger account.

**The demo railway**

| Train | Route | Leaves | Runs |
| --- | --- | --- | --- |
| 701 Shapla Express | Dhaka (Kamalapur) → Chattogram | 07:00 | daily |
| 702 Shapla Express | Chattogram → Dhaka (Kamalapur) | 15:00 | daily |
| 721 Bakul Intercity | Dhaka (Kamalapur) → Chattogram, all stops | 07:45 | Mon–Sat |
| 741 Night Star | Dhaka (Kamalapur) → Chattogram, overnight | 23:30 | daily |
| 753 Kadam Express | Dhaka (Kamalapur) → Rajshahi | 14:45 | Mon–Sat |
| 754 Kadam Express | Rajshahi → Dhaka (Kamalapur) | 07:40 | Mon–Sat |

- Every train has 6 coaches: **KA** AC berth, **KHA** AC chair, **GA** and **GHA** Snigdha, **CHA** and
  **JA** Shovan chair (300 seats). The first two seats of CHA and JA are **women only**. The last few seats of
  each coach are kept for the **station counter** and can't be bought online.
- Up to **4 passengers** per booking. Passengers aged 18 or over need an **NID** (10, 13 or 17 digits; any
  digits work in the demo). Concessions: **Child** 50% off (up to 11), **Senior citizen** 25% off (65+).
- Seats are **held for 10 minutes** while you pay.
- **Refunds** when you cancel, by time before departure: 48 h or more: 90% minus ৳20 · 24–48 h: 75% minus
  ৳20 · 12–24 h: 50% minus ৳20 · under 12 h: no refund. If the railway cancels a train, everyone gets 100%
  back.
- Payments go to a **pretend gateway**. Its page looks like bKash / Nagad / card but no money moves: you
  choose whether the payment succeeds, is declined or is cancelled.
- No real SMS or email is sent. The mobile verification code is shown on screen.

---

## Passenger

**You are:** someone buying a train ticket online. Log in at `<demo>/login` with
`passenger@railway-erp.test`, or register your own account.

1. **Find a train.** Go to **Book tickets**, choose **From** `Dhaka (Kamalapur)`, **To** `Chattogram` and a
   travel date in the next few days, then **Search trains**. Each train shows its seats left and the fare
   per class.
2. **Pick seats.** Press **Choose** on a train. Tap free seats on the seat map to pick them (up to 4), then
   **Hold seats for 10 minutes**. The countdown starts.
3. **Passengers.** Fill in name, age, gender and NID for each seat, choose a concession if one applies, and
   **Save passengers**. The fares are recalculated.
4. **Mobile number.** Enter a mobile number, **Send code**, type the code shown on screen and **Confirm**.
5. **Pay.** Choose bKash, Nagad or card and **Continue to payment**. On the pretend gateway press
   **Pay ৳…** (success), **Simulate a declined payment**, or **Cancel and go back**.
6. **Your ticket.** After paying, **Download ticket** gives a PDF with a QR code. **My bookings** lists all
   your bookings, with the train's live delay if it is running late.
7. **Cancel.** Open a booking and **Cancel this booking**. The screen shows the refund before you confirm.

**Test scenarios**

- [ ] Search a route and date, pick 2 seats, pay successfully, download the ticket.
- [ ] Two people select the same seat at once: only one hold succeeds. *(Do this with a second tester or a
      second browser: both pick the same seat and press Hold at the same moment.)*
- [ ] Hold a seat and wait 10 minutes without paying: the seat is free again (check from a second browser).
- [ ] Payment fails (**Simulate a declined payment**): the booking fails and the seats go back on sale.
- [ ] Book Dhaka → Bhairab Bazar, and from another account book Bhairab Bazar → Chattogram on the **same
      seat** of the same train (use 721 Bakul Intercity or 701 Shapla Express): both work.
- [ ] Hold expires, someone else takes the seat, then the first person's payment succeeds: the late payment
      is refunded and the seat is not sold twice. *(Hold, wait 10 minutes on the gateway page, let a second
      tester buy the seat, then press Pay.)*
- [ ] Cancel 3 days before departure: the refund is 90% minus ৳20.
- [ ] Try to cancel a booking that leaves in less than 12 hours: you can cancel, but there is no refund.
- [ ] Try a women-only seat (CHA or JA, seats 1–2) with a male passenger: it is refused.

---

## Counter clerk

**You are:** selling tickets for cash at a station counter. Log in at `<demo>/admin` with
`counter_clerk@railway-erp.test`.

1. **Sell.** **Bookings → Counter booking**: choose the date and train, the boarding and destination
   stations, pick seats on the map (counter-only seats are available to you), enter the passengers and press
   **Sell for cash**. Then **Print ticket (PDF)**.
2. **Cancel and refund.** **Bookings → Cancel and refund**: enter the PNR, check the refund shown, and
   confirm. For a cash sale, hand the refund over in cash.

**Test scenarios**

- [ ] Sell a seat; it disappears from the online seat map within a few seconds. *(Ask a passenger tester to
      keep the same train's seat map open, or open it yourself in a second browser.)*
- [ ] Sell one of the counter-only seats (the last seats of a coach) that the online map doesn't offer.
- [ ] Cancel a counter booking by PNR and check the refund against the rules in
      [Before you start](#before-you-start).

---

## Operations planner

**You are:** in charge of the network and timetable. Log in with `planner@railway-erp.test`.

1. **Stations and routes.** **Network → Stations**: create a station. **Network → Train routes**: open a
   route and add the station with its distance (km from the start).
2. **Trains.** **Network → Trains → New**: number, name, route, running days, the timetable (arrive /
   depart at each stop) and the coaches in order.
3. **Train runs.** **Network → Train runs** lists each train on each date. **Generate runs** creates the
   next days. **Seat map** shows a run's seats for any part of the journey; click seats and use
   **Block selected** / **Unblock selected** to take seats out of sale (for VIPs, repairs and so on).
4. **Cancel a train.** On **Network → Train runs**, **Cancel run** with a reason. Everyone booked on it is
   refunded in full automatically.
5. **Coach layouts.** **Coaches → Coach types**: create a coach type, then **Seat layout** to draw its seats,
   mark seats **Women only**, and **Save layout**.

**Test scenarios**

- [ ] Create a new station and add it to a route with its distance.
- [ ] Create a train, set running days, timetable and coach composition. Its runs appear for the next days.
- [ ] Design a new coach layout and mark two seats as reserved for women.
- [ ] Block a seat; it can't be booked online or at the counter.
- [ ] Cancel a train run: all its passengers are refunded automatically (ask Finance to check
      **Finance → Refunds**).

---

## Station master

**You are:** recording when trains actually arrive and leave your station. Log in with
`station_master@railway-erp.test`.

1. **Operations → Actual times**: choose today's (or last night's) train.
2. For each stop, type the actual arrival and/or departure as `HH:MM` and save. The train's delay is
   worked out from the latest stop you entered. Times in the future, or more than 2 hours early, are refused.

**Test scenarios**

- [ ] Enter a 25-minute delay (for example a 07:00 departure as `07:25`); a passenger booked on that train
      sees "Running 25 min late" on **My bookings**.
- [ ] Enter the arrival at the last stop: the train shows as arrived.

---

## TTE (ticket examiner)

**You are:** checking tickets on the train. Log in with `tte@railway-erp.test`, **on a phone**. The camera
only works over the `https://` link, not over `http://`.

1. **Checking → Scan tickets**: choose the train you are working. Allow the camera and point it at the QR
   code on a ticket (the PDF can be on another screen or printed). The result shows the passengers and
   seats, and the count of bookings checked so far.
2. **Checking → Passenger chart**: every ticket holder in coach and seat order, with checked / not yet.
   Mark **no-shows**, and record **fines** (cash, with a receipt number).

**Test scenarios**

- [ ] Scan a valid ticket (accepted), then the same ticket again (shows **already used**, with when and by
      whom).
- [ ] Scan a cancelled ticket: rejected.
- [ ] Scan a ticket for the same train on another date: rejected as the wrong date.
- [ ] Mark a passenger as a no-show and record a fine on the passenger chart.

---

## Crew controller

**You are:** assigning drivers, guards and locomotives to trains. Log in with
`crew_controller@railway-erp.test`.

1. **Crew and locos → Staff**: drivers' licence expiry dates are shown; expired and soon-to-expire licences
   are highlighted.
2. **Crew and locos → Locomotives**: **Send to workshop** / **Return to service**, and when maintenance is
   due.
3. **Crew and locos → Assignments**: pick a date and a train; add a driver, a guard and other crew, and
   assign a locomotive. The page lists what each train still needs.

**Test scenarios**

- [ ] Try to assign the driver whose licence has expired: blocked.
- [ ] Try to assign the locomotive that is under repair: blocked.
- [ ] Give one train a full crew and a locomotive: its "still needs" list becomes empty.

---

## Finance

**You are:** checking money in and out. Log in with `finance@railway-erp.test`.

1. **Finance → Transactions**: every payment, with method, amount and status; filter by date.
2. **Finance → Refunds**: every refund; **Retry refund** on one that failed.
3. **Reports → Reports and export**: choose a report and dates, download a **CSV**, or an **Excel** file
   with all reports.
4. The **dashboard** (first page after login) shows revenue, sales by class and channel, and occupancy.

**Test scenarios**

- [ ] Today's revenue on the dashboard matches the sum of today's successful payments minus today's refunds
      (compare with **Finance → Transactions** and **Finance → Refunds** filtered to today).
- [ ] Export the revenue report to Excel and open it.

---

## Manager

**You are:** watching how the railway is doing. Log in with `manager@railway-erp.test`. You can look at
almost everything but change nothing.

1. The **dashboard**: revenue, sales by class and channel, revenue by train, occupancy.
2. **Reports → Reports and export**: the same reports as Finance.
3. Read-only views of the network, fares, crew assignments and payments.

**Test scenarios**

- [ ] The occupancy of a train run in the reports matches its seat map (ask the planner to open
      **Network → Train runs → Seat map** for the same run).
- [ ] Try to change something (a fare, a train): there is no way to.

---

## Admin

**You are:** running the system. Log in with `admin@railway-erp.test`. You can do everything the other staff
roles can, plus:

1. **Fares → Fare rules**: fare per seat class and distance band. **Fares → Refund rules** and
   **Fares → Concessions**.
2. **Admin → Audit log**: every change, who made it and when, with before and after values.
3. **Admin → Demo data**: **Reset demo data** puts everything back to the starting state with a month of
   sample bookings. It deletes all changes and bookings made by testers, so agree on it with the other
   testers first.

**Test scenarios**

- [ ] Change a fare rule and confirm new searches show the new price.
- [ ] The audit log shows who changed the fare rule, with the old and new fare.

---

## Reporting a problem

Write down, for each problem:

1. **Your role** and the **page** (the menu item, or copy the address bar).
2. **What you did**, step by step.
3. **What you expected** and **what happened instead**. A screenshot helps.
4. The **PNR**, if it is about a booking.

Send it to the host (or create a GitHub issue labelled `bug`, `ux` or `missing-feature`).

---

## For the host: running and sharing the demo

**Start** (on the PC that runs the demo):

```powershell
F:\Railway_Management_ERP\tools\start-mysql.ps1
cd F:\Railway_Management_ERP\railway-erp
php artisan serve --host=127.0.0.1 --port=8000
php artisan schedule:work   # second window: releases expired holds, daily runs and warnings
php artisan queue:work      # third window: ticket emails (written to the log; no real email is sent)
```

**Password:** `DEMO_PASSWORD` in `.env` sets the password of every demo account
(`php artisan db:seed --class=DemoUsersSeeder` applies a change).

**Fresh start with sample history:** `php artisan demo:reset` (or **Admin → Demo data**). It deletes
everything first and takes a minute or two.

**Share with testers over HTTPS** (needed for phones and the TTE camera). *Not yet tried end to end on this
PC.*

1. Install Cloudflare's tunnel once: `winget install Cloudflare.cloudflared`.
2. In `.env`, set `TRUSTED_PROXIES=127.0.0.1,::1`, so the app trusts the tunnel and builds `https://` links.
   Then run `php artisan config:clear` and restart `php artisan serve`.
3. Run `cloudflared tunnel --url http://127.0.0.1:8000`. It prints an address like
   `https://random-words.trycloudflare.com`. Send that to the testers. It changes every time you restart
   the tunnel, and it stops when you close the window.
4. Optional: set `APP_URL` to the same address so links in emails point to it.
5. When you stop sharing, empty `TRUSTED_PROXIES` again.

The demo only works while this PC, MySQL, `php artisan serve` and the tunnel are running.
