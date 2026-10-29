# Smart Hotel

Hotel management & security platform by **Qudroid Systems** (Laravel 12, Blade, Spatie Permission).

This is the starter core: the master layout and UI kit, authentication, user
management, roles & permissions, profile settings and a hotel overview dashboard.
Hotel modules (rooms, reservations, QR check-in, POS, inventory, HR, security,
IT control room…) are built on top of it.

## Setup (XAMPP)

1. Create an empty MySQL database, e.g. `smart_hotel`.
2. Copy `.env.example` to `.env` (or edit your existing one) and set `DB_*`,
   `HOTEL_NAME`, and `ADMIN_EMAIL` / `ADMIN_PASSWORD` for the first account.
3. Set up the database:

   ```bash
   php artisan key:generate        # only if APP_KEY is empty
   php artisan migrate --seed
   php artisan storage:link
   ```

   Optional demo data, in this order: `php artisan db:seed --class=RoomDemoSeeder`,
   `php artisan db:seed --class=ReservationDemoSeeder`, `php artisan db:seed --class=PosDemoSeeder`,
   `php artisan db:seed --class=InventoryDemoSeeder`, `php artisan db:seed --class=AttendanceDemoSeeder`
   (14 demo staff — they sign in with their email and `Staff@123`).

4. Open `http://localhost/hotel/public` and sign in with the admin account.
   You'll be asked to set a new password on first sign-in.

## What's included

| Area | Files |
|---|---|
| Layout & UI | `resources/views/layouts/master.blade.php`, `public/css/cb-ui.css`, `resources/views/components/cb/*` (`<x-cb.hero>`, `<x-cb.stat>`, `<x-cb.card>`), `public/theme` |
| Auth | `Auth/LoginController` (email **or** username), password reset. Public sign-up is off — admins create accounts. |
| Users | `UserController`, `resources/views/users/*` |
| Profile | `ProfileController` — own profile, avatar, email, password |
| Roles & permissions | `RoleController`, `PermissionController`, `resources/views/roles/*`, `resources/views/permissions/*`, `App\Support\PermissionMeta` |
| Dashboard | `DashboardController`, `resources/views/dashboards/dashboard.blade.php` |
| Rooms & property | `Rooms\RoomController` (room board, list, bulk add, status changes), `Rooms\RoomTypeController` (rates, amenities, photos), `Rooms\BuildingController` (buildings & floors), `App\Enums\RoomStatus`, `resources/views/rooms/*`. Try it with `php artisan db:seed --class=RoomDemoSeeder`. |
| Reservations & front desk | `Reservations\ReservationController` (front desk tabs, booking form with live availability, check-in/out, room moves, date changes, discounts, cancel/no-show, booking calendar, QR scan & lookup, printable confirmation), `Reservations\GuestController` (guest directory), `App\Services\Reservations\AvailabilityService` + `ReservationService` (all booking rules), `resources/views/reservations/*`, `resources/views/guests/*`. Demo: `php artisan db:seed --class=ReservationDemoSeeder` after the room demo. |
| Guest billing | `Billing\FolioController` (the bill: charges, discounts/adjustments, payments, deposits, refunds, voids with reasons, printable invoice & receipts), `Billing\BillingController` (collections / cash-up by method and cashier, outstanding bills & credits), `App\Services\Billing\FolioService` (room nights posted automatically, tax snapshot, balance check at check-out), `resources/views/billing/*`. Taxes in `.env` (`HOTEL_VAT_RATE`, `HOTEL_PRICES_INCLUDE_TAX`…). Payment and charge forms carry a one-time key so a retried submit is never recorded twice. |
| Bank accounts | `Settings\BankAccountController`, table `hotel_bank_accounts` — printed on invoices, chosen when a transfer is received, totalled per account on the cash-up. |
| Bar & restaurant POS | `Pos\PosTerminalController` (the till, `/pos`), `Pos\PosOrderController` (sales, voids, settling flagged sales, receipts), `Pos\PosMenuController` (outlets, categories, items & prices), `App\Services\Pos\PosService`, `public/js/pos-terminal.js`, `public/css/pos-terminal.css`, `public/pos-sw.js`. Demo: `php artisan db:seed --class=PosDemoSeeder`. |
| Inventory & stock | `Inventory\StockController` (stock on hand, item ledger, adjustments, waste), `ReceiptController` (goods received), `PurchaseOrderController`, `TransferController` (between stores), `CountController` (stocktakes), `SupplierController`, `RecipeController` (what each POS item uses), `InventorySettingsController` (stores, categories, which store each outlet sells from), `InventoryReportController` (value, margins, losses, movements). All quantity changes go through `App\Services\Inventory\StockService` into the `stock_movements` ledger (weighted average cost). POS sales take stock when they reach the server and put it back if voided — once only, even if a till re-sends. Demo: `php artisan db:seed --class=InventoryDemoSeeder` after the POS demo. |
| Staff attendance | `Attendance\AttendanceController` (who's in today, a person's month, timesheet + CSV, leave, my attendance), `CorrectionController` (add/remove clock-ins with a reason, rota changes, leave), `SetupController` (staff & badges, shifts, departments, kiosks; printable ID cards), `KioskController` (the clock-in kiosk, `/attendance/kiosk`), `App\Services\Attendance\AttendanceService` (night shifts, lateness, early leave, overtime, absences), `public/js/attendance-kiosk.js`. Demo: `php artisan db:seed --class=AttendanceDemoSeeder`. |
| Housekeeping | `Operations\HousekeepingController` (`/housekeeping`: board of rooms to clean, being cleaned, awaiting inspection and ready today, with turnaround times; assign, start, done, pass / send back; stay-over tasks for occupied rooms), `App\Services\Operations\HousekeepingService`. Any room set to Needs Cleaning (check-out, room move, repair finished) gets a task automatically; the room goes back on sale only after a supervisor passes it (`HOTEL_HOUSEKEEPING_INSPECTION=false` to skip inspection), and straight to Reserved if a guest arrives today. Roles: Housekeeping, Housekeeping Supervisor. |
| Maintenance | `Operations\MaintenanceController` (`/maintenance`: report a fault with photos, priority and due time, assign a technician, start / hold / complete with cost and after-photos, cancel; room page shows its repair history), `App\Services\Operations\MaintenanceService`. A job can keep its room off sale (Under Maintenance, or held back when the guest checks out); when the last such job is done the room goes to housekeeping. Housekeepers can report a fault from the board. Role: Maintenance Technician. |
| Security | `Security\VisitorController` (`/security/visitors`: sign visitors in with ID, host or in-house guest, vehicle and party size; printable pass with a QR; `/security/visitors/check` scans or types a pass code and shows valid / expired / signed out; sign out and extend), `Security\IncidentController` (`/security/incidents`: report with type, severity, place, people involved, police reference and photos; assign, timeline of updates, resolve and close; emergency contact list), `Security\LostItemController` (`/security/lost-found`: log found items with photo and storage place, hand back only with the owner's ID noted, or dispose). `App\Services\Security\SecurityService`. High and critical incidents, unknown passes and handover of valuables are flagged on the audit trail. `security:prune-visitors` (nightly) clears phone, ID number and plate from visits older than `HOTEL_VISITOR_RETENTION_DAYS`. |
| Procurement | `Inventory\PurchaseRequestController` (`/inventory/requests`: a department asks for stock, a manager approves or rejects, the buyer turns it into a draft purchase order), purchase order approval on `Inventory\PurchaseOrderController` (orders over `HOTEL_PO_APPROVAL_LIMIT` go to *Awaiting approval*; nobody approves an order or request they raised; the order page compares each price with the last price paid and warns above `HOTEL_PRICE_JUMP_PERCENT`), goods refused at delivery on the GRN (stay outstanding on the order), `Inventory\SupplierInvoiceController` (`/inventory/invoices`: record a supplier's bill against a delivery, three-way check of ordered / received / invoiced, payments, what is owed per supplier, overdue bills, deliveries with no bill yet). `App\Services\Inventory\ProcurementService`. The item page shows prices paid by supplier. |
| Management reports | `Reports\ReportController` (`/reports`: occupancy, ADR, RevPAR, revenue by department and outlet with tax, earned vs collected vs owed, bookings by source, best sellers, day-by-day table; compares with the previous period; daily and revenue CSV exports), `App\Services\Reports\ManagementReportService`. Rooms sold are counted from room nights posted to guest bills; bar sales charged to rooms are counted once, under their outlet. Permission: `View management reports`. |
| Cashier shifts | `Billing\CashShiftController` (`/cash-shifts`: open with a float, paid in / paid out / drop to safe with a reason, close with a note-by-note cash count, supervisor sign-off, printable shift report), `App\Services\Billing\CashShiftService`. A shift covers whatever its cashier took between opening and closing (guest bill payments and refunds, bar sales paid at their till), so the till and front desk work as before. Expected cash is frozen at close. A count off by more than `HOTEL_CASH_VARIANCE_TOLERANCE` (default 100) needs a note and is flagged on the audit trail. Nobody signs off their own shift. Permission: `Review cash shifts`. |
| Audit trail | `AuditLogController` (`/audit-trail`: filter by date, person, area, flagged; history of one record; CSV export), `App\Support\Audit::record()` (write an entry from anywhere), `App\Providers\AuditServiceProvider` (records sign-ins and failed sign-ins, set-up changes with before/after values — rates, prices, users, roles —, voids, refunds, discounts, stock adjustments, attendance corrections, check-in/out and QR scans). Table `audit_logs` is append-only; passwords and tokens are never stored. Large voids/refunds/discounts (`HOTEL_AUDIT_FLAG_AMOUNT`, default 50,000) and repeated failed sign-ins or scans (`HOTEL_AUDIT_FLAG_FAILURES`, default 5 in 15 min) are flagged. Permission: `View audit log`. |
| Two-step sign-in | `Auth\TwoFactorController` (`/two-factor`: set up with any authenticator app by QR code or key, 8 one-off recovery codes, move to a new phone, turn off), `Auth\TwoFactorChallengeController` (the code step after the password, website), staff app login takes a `code` field (`two_factor_required` / `two_factor_invalid` / `two_factor_setup_required`), `Security\TwoFactorAdminController` (`/security/two-step`: who uses it, reset with a reason; permission `Update user`). Roles in `HOTEL_2FA_ROLES` (default Super Admin, Hotel Owner, General Manager) must set it up before using anything else. Codes are RFC 6238 (`App\Support\Auth\Totp`), each accepted once; the key and recovery codes are stored encrypted. Locked out with no phone and no codes: `php artisan hotel:2fa-reset email@or-username`. |
| Department tasks | `Operations\TaskController` (`/tasks`: give a task to a department or a person with priority, due time, room or place, photos and documents, and an optional supervisor check; everyone sees *My tasks* and *My department*, anyone in the department can take an unassigned task; start, mark done with notes and photos; the giver, the department's manager or `Manage tasks` checks it — never the person who did it — or sends it back; history of every change). `App\Services\Operations\TaskService`. `tasks:escalate` (every 15 minutes): when a task passes its due time the person (or department) and the department manager are reminded; if it is still not done after 1 h (urgent), 2 h (high), 4 h (normal) or a day (low), everyone with `Manage tasks` is told and it is flagged on the audit trail. Department managers are set on the departments page. Permissions: `Give tasks`, `View all tasks`, `Manage tasks`. |
| Public website | `Website\SiteController` (the public pages at `/`), `Website\WebsiteAdminController`, `WebsiteContentController`, `WebsiteEnquiryController` (Website in the sidebar), `App\Support\Website` (settings and colour schemes), `App\Models\Website\*`, `App\Services\Website\WebsiteService`, `resources/views/site/*`, `resources/views/website/admin/*`, `public/site/*`. See **Public website** below. |
| Seeders | `PermissionTableSeeder` (core permissions), `RoleTableSeeder` (the 13 hotel roles from the spec), `UserTableSeeder` (first Super Admin) |

The **Super Admin** role passes every permission check (`AppServiceProvider`)
and cannot be deleted, renamed, or removed from yourself.

## Branding

Set in `.env` (see `config/hotel.php`): `HOTEL_NAME`, `HOTEL_TAGLINE`,
`HOTEL_LOGO`, `HOTEL_LOGO_LIGHT`, `HOTEL_FAVICON` (paths relative to `public/`).

## Public website

The main address (`/`) is now the hotel's public website: home, rooms (from Room Types), dining,
meetings & events (halls and ticketed events), experiences, offers, gallery, journal, about and contact.
Every "Book" button opens the online booking pages (`/book`), and an offer's promo code is filled in.
Staff sign in at `/login` as before; with the website switched off (Website → Settings & Texts), `/`
goes to the sign-in page again.

- Content: **Website** in the sidebar (permission `Manage website`): home page slides, highlights,
  dining, experiences, offers (with promo code and dates), gallery, journal, reviews, FAQs and extra pages.
  Each item can be hidden, featured, scheduled and reordered.
- Settings & Texts: tagline, colour scheme, announcement bar, the home page texts and numbers, each page's
  heading and picture, contact details, opening hours, map, social links and SEO.
- Enquiries: contact, table and event forms from the website and the guest app land under Website →
  Enquiries (permission `View website enquiries`) and are e-mailed to the address in Settings. Newsletter
  sign-ups can be exported to CSV.
- Pictures are stored in `public/uploads/website` (no `storage:link` needed on XAMPP).
- Fonts (Cormorant Garamond, Jost; OFL) are bundled in `public/site/fonts`, so the site works offline.
- Guest app: `GET /api/v1/guest/website` gives the same content for the app's home screen;
  `POST /api/v1/guest/website/enquiries` and `/subscribe` take its forms.
- Sample content: `php artisan db:seed --class=WebsiteDemoSeeder` (uses the admin theme's pictures).

## Adding a module

1. Permissions: add a group to `PermissionTableSeeder::PERMISSIONS`, grant them in
   `RoleTableSeeder::ROLES`, then run `php artisan db:seed`.
2. Routes: add them inside the `auth` group in `routes/web.php`.
3. Menu: add a sidebar block under the **HOTEL MODULES** comment in
   `layouts/master.blade.php`, wrapped in `@can(...)`, plus a Spotlight entry
   in `STATIC_PAGES`.
4. Views: `@extends('layouts.master')` and set `$pagetitle` in the controller.

## Working through network failures

* **Run the server inside the hotel** (this XAMPP machine, or a small server on the
  hotel LAN). Then an internet outage doesn't stop anything — all scripts, styles,
  fonts and icons are in `public/vendor`, nothing is loaded from the internet.
* **The till (`/pos`) works with no network at all.** It keeps the menu and the
  in-house guest list on the device; each sale is saved on the device first
  (IndexedDB) and sent to `/pos/sync` automatically when the server can be reached.
  Every sale has a random id the server accepts only once, so a retry after a
  timeout never double-counts. If the sign-in expires while offline, sales wait
  on the device until someone signs in again. A sale the server can't settle as
  sent (e.g. a room charge for a guest whose booking was cancelled) is kept and
  shown as **Needs attention** on the Sales page and the dashboard.
* **Re-opening the till while offline** needs the browser's offline copy, which
  browsers only allow on `https://` or `http://localhost`. On a plain
  `http://192.168…` address the till still works offline as long as the page stays
  open (it warns before closing). For tills on other devices, serve the site over
  HTTPS on the LAN (e.g. XAMPP's SSL with a local certificate).
* **The clock-in kiosk (`/attendance/kiosk`) works the same way**: it keeps every
  badge as a hash, saves each scan (and photo) on the device, and sends them when
  it can. Badges are read by the tablet camera (https or localhost only) or by a
  USB / Bluetooth QR scanner, which works on any address.
* **Other pages** show a banner when the connection drops, refuse to send a form
  while offline (what was typed stays on the page), and stop double submits.

## Scheduler (optional)

Rooms assigned to today's arrivals are marked **Reserved**, and last night's
room charges are posted to in-house guests' bills, automatically the first time
someone opens the front desk each day (bills also update whenever they are opened). On a server, also add the
Laravel scheduler to cron so it happens overnight (`rooms:prepare-arrivals` 00:05,
`folios:night-audit` 02:00):

```
* * * * * php /path/to/hotel/artisan schedule:run >> /dev/null 2>&1
```

## Mobile API (later)

Laravel Sanctum was removed until the mobile/POS API is built. When it is:
`composer require laravel/sanctum`, add `HasApiTokens` back to `User`, and
re-add `routes/api.php` in `bootstrap/app.php`.
