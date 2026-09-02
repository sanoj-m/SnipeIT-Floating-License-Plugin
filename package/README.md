# Floating / Concurrent Licenses for Snipe-IT

Adds floating (concurrent) software license pools on top of Snipe-IT's existing
fixed-seat licenses.

A fixed-seat Snipe-IT license assigns one named seat per user/asset and the
seat stays assigned until checked in. A floating pool instead owns a number of
concurrent-use *slots* drawn from the license's own seat count. Typical use
case (Enscape-style): 17 seats, 35 users, everyone shares, and the license
cost is spread across all active users.

## Overview

- Pool configs (`floating_license_configs`) attach 1:1 to an existing Snipe-IT
  license. The license keeps its normal seats, checkout history, and UI.
  `licenses.seats` is the pool size and `licenses.purchase_cost` is the total
  cost — the config only adds `cost_mode`, `allow_over_allocation`, and
  (optional) durations.
- Allocations (`floating_license_allocations`) record who holds a slot and a
  snapshot of the per-allocation cost.
- A **master switch** in Admin > Settings > General ("Floating Licenses
  (addon)") turns the whole addon on/off: license-form section, package web
  pages, API endpoints, and license-form syncing are all inert while it is
  off (web/API return 403).
- All state changes go through `FloatingLicenseService` and write an entry to
  the standard Snipe-IT activity report (`action_logs`).
- Allocation is concurrency-safe: the pool config row is re-read with
  `lockForUpdate()` inside a transaction before the capacity check.

## Requirements

- Snipe-IT on Laravel 12 (this repository's `master`)
- PHP 8.2+
- No additional Composer packages

## Installation

The package is wired in as a Composer path repository. In this repository both
steps are already done — root `composer.json` contains:

```json
"repositories": [
    {
        "type": "path",
        "url": "packages/floating-licenses",
        "options": { "symlink": true }
    }
],
"require": {
    "snipe-it/floating-licenses": "*"
}
```

If you are installing into a different Snipe-IT checkout, add those two blocks
first. Then:

```bash
composer update snipe-it/floating-licenses
php artisan migrate
php artisan optimize:clear
```

The service provider (`SnipeIt\FloatingLicenses\FloatingLicensesServiceProvider`)
is registered by Laravel package auto-discovery (`extra.laravel.providers` in
the package `composer.json`).

The provider:

- loads the package migrations (the two floating tables, the nullable-duration
  alteration, and the `floating_licenses_enabled` column on the core
  `settings` table), routes, views, and translations,
- merges a "Floating Licenses" section into `config('permissions')` at runtime
  so the group/user permission pages pick it up (core `config/permissions.php`
  is untouched),
- defines gates for the six permissions (mirroring how `AuthServiceProvider`
  defines gates such as `reports.view`),
- registers the `floating-licenses:expire` console command.

After migrating, turn the addon on via **Admin > Settings > General >
Floating Licenses (addon)**.

## Core file modifications

The addon deliberately makes a few small, guarded edits to core files. Each
is safe to re-apply after a Snipe-IT upgrade (see below):

| Core file | Change |
|---|---|
| `app/Http/Controllers/Licenses/LicensesController.php` | One line at the top of the `if ($license->save())` block in **both** `store()` and `update()`: `\SnipeIt\FloatingLicenses\Support\FloatingLicenseSync::syncFromRequest($license, $request);` (no-op when the master switch is off). |
| `resources/views/licenses/edit.blade.php` | A "Floating / Concurrent" section before the form footer: `floating_enabled` checkbox, `floating_cost_mode` select, `floating_allow_over_allocation` checkbox (hidden 0 + checkbox 1). Whole block wrapped in `@if (($snipeSettings->floating_licenses_enabled ?? '0') == '1')`. |
| `resources/views/licenses/view.blade.php` | A guarded `@php` block at the top of `@section('content')` resolving the floating config via `\SnipeIt\FloatingLicenses\Support\FloatingLicenseSync::configForLicense($license)`; floating info **rows inside the license info panel** (default slot of `x-info-panel`: License Type, Pool Size, Assigned Users, Available, Total Cost, Cost Per User); a floating-assigned-users table + over-allocation warning label inside the Assigned seats tab pane (with a per-row Checkin button posting to the release route); a Bootstrap-3 "Bulk User Actions" dropdown in the info-panel buttons slot; an info callout in the Available tab explaining that per-seat checkout is disabled for floating licenses; and a small `<script id="floating-remaining-override">` that replaces the core "Remaining" info row's value client-side with the floating availability (the row lives in the shared `x-info-panel` component, which stays untouched). Everything is wrapped in the master-switch guard and `@can` permission gates. |
| `app/Http/Transformers/LicensesTransformer.php` | One commented hunk at the end of `transformLicenses()` (`// [floating-licenses addon] BEGIN/END`): when the master switch is on, one query on `floating_license_configs` + one grouped count query on `floating_license_allocations` for the page's license ids, then `free_seats_count` / `remaining` are recomputed as `seats - active floating allocations` (may go negative) and `percent_remaining` is recomputed clamped to 0-100. |
| `app/Http/Transformers/LicenseSeatsTransformer.php` | One commented hunk in `transformLicenseSeats()`: one query collecting which of the page's licenses have a floating pool, then `available_actions.checkout` and `user_can_checkout` are forced `false` on those seats so `licenseSeatInOutFormatter` renders no per-seat Checkout button (checkin of genuinely seat-assigned rows is untouched). |
| `app/Http/Controllers/SettingsController.php` | One line in `postSettings()` next to the other checkbox settings: `$setting->floating_licenses_enabled = $request->input('floating_licenses_enabled', '0');` |
| `resources/views/settings/general.blade.php` | One `x-form.checkbox-row` ("Floating Licenses (addon)") after the Shortcuts checkbox. |
| `app/Models/Setting.php` | **No change needed** — the setting is assigned directly (like `shortcuts_enabled`), not mass-assigned, and the column is created by a package migration. |
| `app/Http/Controllers/Licenses/LicenseCheckoutController.php` | One commented hunk in `store()` (`// [floating-licenses addon] BEGIN/END`), anchored right after `$this->authorize('checkout', $license);`: when `FloatingLicenseSync::configForLicense($license)` returns a config, resolve the user target (`assigned_user`, or the assigned asset's current user, mirroring `checkoutToAsset()`) and delegate to `FloatingLicenseService::allocate()` instead of a seat checkout; `PoolExhaustedException` redirects to the license page with an error flash. Pure asset checkouts with no resolvable user fall through to the core flow. The `LicenseCheckoutRequest` form request is untouched. |
| `resources/views/licenses/checkout.blade.php` | One commented hunk at the top of `@section('content')` resolving the floating config/stats; the `x-box` header's `seat_count` uses pool availability (`pool - active`) when floating; a `callout callout-warning` is shown when the pool is exhausted and over-allocation is off. |

### Re-applying after a Snipe-IT upgrade

1. Pull/upgrade Snipe-IT core as usual; if any of the nine edited files
   conflict, take the upstream version and re-apply the hunks listed in the
   table above (they are intentionally tiny and self-contained; PHP hunks are
   delimited by `// [floating-licenses addon] BEGIN/END` comments).
2. `composer update snipe-it/floating-licenses`
3. `php artisan migrate` — package migrations are idempotent on upgrade.
4. `php artisan optimize:clear`

If the package is not installed, the core edits would reference a missing
class — either keep the package installed or remove the nine edits.

## Configuration

Defaults live in `config/floating-licenses.php` inside the package. To
customize them, publish the file:

```bash
php artisan vendor:publish --tag=floating-licenses-config
```

Keys (each pool can override all of them individually):

| Key | Default | Meaning |
|---|---|---|
| `lease_duration_minutes` | `null` | How long an allocation lease lasts before it may be expired. `null` = never expires. |
| `idle_timeout_minutes` | `null` | An allocation with no heartbeat for this long is considered idle. `null` = no idle reclamation. |
| `cost_mode` | `pool_slot` | `pool_slot` or `active_user` (see below). |
| `allow_over_allocation` | `false` | Whether pools may exceed `pool_size`. |
| `log_heartbeats` | `false` | Write an activity log entry for every heartbeat (noisy; off by default). |

## Usage

### Master switch ON means EVERY license behaves floating

When `floating_licenses_enabled` is on, a license without an explicit pool
config still behaves floating: `FloatingLicenseSync::configForLicense()`
lazily creates AND persists a default config from the license's own
attributes (`seats` = pool size, `purchase_cost` = total cost,
`cost_mode = active_user`, `allow_over_allocation = true`, no durations), so
the total cost is spread across all assigned users. Bulk-add on such a
license creates floating allocations (never core seat checkouts). With the
master switch OFF, bulk routes are 403 and the assignment layer falls back to
normal core seat checkout for config-less licenses.

The per-license "Floating / Concurrent" checkbox on the license edit form
still exists to customize cost mode / over-allocation, and to have the config
survive the master switch being turned off later.

### Enable floating on a license

Edit the license itself (Hardware > Licenses > edit): the **Floating /
Concurrent** section has an enable checkbox, a cost-mode select, and an
over-allocation checkbox (checked by default). Pool size and total cost come
from the license's own **Seats** and **Purchase Cost** fields — changing those
fields re-syncs the pool config on save. Unchecking the box soft-deletes the
config, but only while there are no active allocations; otherwise it is kept
and a warning is shown.

Alternatively, the standalone **Floating Licenses** pages
(`/floating-licenses`) still allow managing pools directly.

### License view page integration

With the master switch on, the license view page shows:

- **Info rows in the license info panel** — License Type (Fixed Seats /
  Floating / Concurrent), Pool Size, Assigned Users, Available (may go
  negative), and — only with the `floating_licenses.costs` permission — Total
  Cost and live Cost Per User.
- **Floating-assigned users in the Assigned tab** — a static table (User,
  Asset, Allocated At, Allocated Cost) beneath the seats datatable. When
  active allocations exceed the pool, a warning label
  (`Over-allocated: N assigned / M seats (+X)`) appears there and as a compact
  `+X` label next to the License Type row — regardless of the pool's
  over-allocation setting (it is informational).
- **A "Bulk User Actions" dropdown** in the license's action buttons:
  **Bulk Add Users** (needs `floating_licenses.allocate`) and **Bulk Remove
  Users** (needs `floating_licenses.release`).

### Seat math consistency

Core's seat-based numbers never see floating allocations, so the addon adjusts
the three places they surface (master switch on, floating config present
only):

- **Licenses index API** (`/api/v1/licenses`): `Avail`/`Remaining` become
  `seats - active floating allocations` (may go negative); `% Remaining` is
  recomputed and clamped to 0-100 for the progress bar.
- **License view info panel**: the core "Remaining" row's value is replaced
  with the floating availability (warning label when negative).
- **License view Available tab**: per-seat Checkout buttons are suppressed
  (floating users don't occupy seat records), with an explanatory callout;
  the seat inventory rows themselves stay visible.

### Allocate / release

Single checkout is intercepted: using core's normal checkout flow on a
license with a floating pool allocates a pool slot via
`FloatingLicenseService::allocate()` (user target, optional asset) instead of
occupying a `license_seats` record; a full pool without over-allocation
refuses with an error flash. The core checkout page shows pool availability
(`pool - active`, may be negative) and a warning callout when the pool is
exhausted and over-allocation is off.

From the pool page (`/floating-licenses/{config}`), allocate a slot to a user
(and optionally an asset). The slot holder can release their own allocation
(with only the `floating_licenses.allocate` permission); releasing *someone
else's* allocation requires `floating_licenses.release`. Each floating row in
the license view's Assigned tab also has a Checkin button posting to the same
release route, which redirects back to the referer (falling back to the pool
page).

### Bulk add / bulk remove users

The dropdown links lead to package form pages, which post to the package:

- `GET/POST /floating-licenses/license/{license}/bulk-add` (`user_ids[]`,
  permission `floating_licenses.allocate`) — creates an active allocation per
  selected user (via the resolver, so config-less licenses get their default
  pool first), skipping users who already hold one.
- `GET/POST /floating-licenses/license/{license}/bulk-remove` (`user_ids[]`,
  permission `floating_licenses.release`) — releases the users' active
  allocations AND checks in any core seats checked out to them
  (`CheckoutableCheckedIn`, unreassignable-seat flag honored), so a license
  in a mixed state (floating allocations + legacy seat checkouts) is cleaned
  up by one action. The remove form lists both groups separately.

Both POST handlers redirect back to the license page with a success/warning
flash reporting added/removed, skipped, and failed counts.

## Cost modes

Each pool carries a `total_cost` (the license's `purchase_cost`) and snapshots
a per-allocation `allocated_cost` that is recalculated whenever the set of
active allocations changes.

- **`pool_slot`** — the cost is split evenly across the slots, whether or not
  they are in use. A $10,000 pool with `pool_size = 10` charges **$1,000** to
  every active allocation — always, even if only one slot is occupied.
- **`active_user`** — the full cost is split evenly across the *currently
  active* allocations. This is the mode for over-allocated shared pools: a
  $300 pool with 3 active users (on 2 slots, over-allocation on) charges
  **$100** to each; when one releases, the remaining two go to **$150**.

Both modes round to 2 decimals, and a missing/zero `total_cost` yields a
`0.00` allocated cost. Costs are displayed as money (currency + formatting
from Snipe-IT settings).

## Leases, heartbeats, and expiration (optional)

Durations are **optional and null by default**: allocations from a pool with
no lease duration never expire (`expires_at = null`), heartbeat calls remain
valid but simply refresh `last_seen_at`, and `floating-licenses:expire` only
ever expires allocations whose pool HAS durations set (past `expires_at`, or
idle past `idle_timeout_minutes`).

Stale allocations are reclaimed by:

```bash
php artisan floating-licenses:expire
```

### Scheduling (the one optional core edit)

Snipe-IT schedules commands in `app/Console/Kernel.php`. To expire stale
allocations automatically, add one line to `schedule()`:

```php
$schedule->command('floating-licenses:expire')->everyFiveMinutes();
```

This is optional — the command can also be run from cron directly, and pools
without durations never need it.

## Permissions

Six permissions are provided; they appear under a **Floating Licenses** section
on the group and user permission pages (merged into `config('permissions')` at
runtime, so no core config edit is needed):

| Permission | Grants |
|---|---|
| `floating_licenses.view` | View pool list and pool detail pages; API availability endpoint. |
| `floating_licenses.manage` | Enable, edit, and disable pool configurations. |
| `floating_licenses.allocate` | Allocate slots; bulk-add users; release/heartbeat **own** allocations. |
| `floating_licenses.release` | Release/heartbeat **any** user's allocation; bulk-remove users. |
| `floating_licenses.costs` | Reserved for cost reporting visibility. |
| `floating_licenses.history` | See the allocation history panel on the pool detail page. |

Superusers bypass all of these as usual.

## API endpoints

All endpoints live under `/api/v1/`, use existing Snipe-IT API token
authentication (Laravel Passport, `auth:api` via the `api` middleware group),
respond with the standard Snipe-IT envelope (`status` / `messages` /
`payload`), and return 403 while the master switch is off.

| Method | Endpoint | Permission | Description |
|---|---|---|---|
| `POST` | `/api/v1/floating-licenses/{license}/allocate` | `floating_licenses.allocate` | Allocate a slot. Body: `user_id` (required), `asset_id`, `notes`. 422 + error envelope when the pool is exhausted. |
| `GET` | `/api/v1/floating-licenses/{license}/availability` | `floating_licenses.view` | Pool stats: `pool_size`, `active`, `available`, `over_allocation_allowed`. |
| `POST` | `/api/v1/floating-license-allocations/{allocation}/heartbeat` | own allocation (`allocate`) or `release` | Extend the lease (no-op besides `last_seen_at` for pools without a lease duration). 422 when the allocation is not active. |
| `POST` | `/api/v1/floating-license-allocations/{allocation}/release` | own allocation (`allocate`) or `release` | Release the slot. 422 when the allocation is not active. |

`{license}` is the core Snipe-IT license id; a license with no floating config
returns 404.

## Audit events

Every state change writes an `action_logs` row with `item_type =
App\Models\License`, `item_id` = the license id, and `target_*` = the affected
user, so floating activity shows up in the standard activity report:

| `action_type` | When |
|---|---|
| `floating.allocate` | Slot allocated. |
| `floating.release` | Allocation released. |
| `floating.revoke` | Allocation administratively revoked (`FloatingLicenseService::revoke()`). |
| `floating.expire` | Allocation expired by `floating-licenses:expire`. |
| `floating.heartbeat` | Heartbeat received (only when `log_heartbeats` is enabled). |
| `floating.capacity_exceeded` | Allocation denied because the pool was full. |

Bulk operations on **fixed** licenses log normal core `checkout`/`checkin`
history entries instead.

## Tests

The package ships a feature test suite under `packages/floating-licenses/tests`
that runs against the host application's `Tests\TestCase`
(`LazilyRefreshDatabase`, factories, `actingAsForApi()`). The package TestCase
enables the `floating_licenses_enabled` master switch in `setUp()`;
`disableFloatingLicenses()` exercises the off behavior.

Because Composer does not merge a path package's `autoload-dev` into the root
autoloader, the root `composer.json` registers the test namespace itself
(already done in this repository):

```json
"autoload-dev": {
    "psr-4": {
        "SnipeIt\\FloatingLicenses\\Tests\\": "packages/floating-licenses/tests/"
    }
}
```

Run `composer dump-autoload` after adding that, then:

```bash
vendor/bin/phpunit --testsuite FloatingLicenses
# or
php artisan test packages/floating-licenses/tests
```

## Upgrade guide

See **Re-applying after a Snipe-IT upgrade** above. No data migration is
needed for fixed-seat licenses; they are untouched.

## Uninstall guide

1. Roll back the package's migrations (**`floating_license_configs` and
   `floating_license_allocations` data will be dropped, and the
   `floating_licenses_enabled` column removed from `settings`**):

   ```bash
   php artisan migrate:rollback --path=packages/floating-licenses/database/migrations
   ```

2. Remove the five core edits listed in **Core file modifications**.
3. Remove `"snipe-it/floating-licenses": "*"` from the root `composer.json`
   `require` block (and the `repositories` entry and the `autoload-dev` test
   mapping if you added them).
4. `composer update snipe-it/floating-licenses`
5. `php artisan optimize:clear`

If you added the scheduler line to `app/Console/Kernel.php`, remove it too.
Fixed-seat licenses, seats, and their history are not affected at any point.
