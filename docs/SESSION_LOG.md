# Session Log — Snipe-IT Floating License Plugin

Date: 2026-09-02
Scope: full build, live deployment, rework rounds, packaging, and publishing of the
floating / concurrent license addon for Snipe-IT.

## 1. Initial build (standalone addon)

- Built as a composer path package at `packages/floating-licenses/`, namespace
  `SnipeIt\FloatingLicenses\`, auto-discovered service provider.
- Delivered: migrations (`floating_license_configs`, `floating_license_allocations`),
  models, `FloatingLicenseService` (allocate/release/heartbeat/revoke/expireStale/
  availability, transaction + `lockForUpdate` race protection), web + API
  controllers, AdminLTE views, translations, `floating-licenses:expire` command,
  6 permissions (`floating_licenses.view/manage/allocate/release/costs/history`)
  merged into `config('permissions')` at runtime, audit events via `Actionlog`
  (`floating.allocate/release/heartbeat/expire/revoke/capacity_exceeded`).
- Two cost modes: `pool_slot` (total/pool) and `active_user` (total/active,
  recalculated on every allocate/release).

## 2. Live server deployment (40.30.20.6, `/opt/snipe-it`, nginx, PHP 8.3, Snipe-IT v8.7.2)

- No sshpass/plink locally → used Node `ssh2` scripts for exec + base64 uploads.
- DB backup before any change: `/root/snipeit_db_pre_floating_20260902_090005.sql`.
- Server composer quirks: `composer config use-github-api false` (github auth
  failure on the laravel-scim-server VCS repo) and `"@dev"` stability constraint.
- Migrations ran on production DB; tests run against separate `snipeit_test` DB
  with `.env.testing` (created server-side).
- First run: 44/47 → fixed 3 issues (capacity-exceeded audit row rolled back by
  transaction; API 422/404 semantics) → 47/47.

## 3. Rework round 1 — integration into core license UI

User direction: floating must live inside Snipe-IT's license pages, not a
separate section; no durations; over-allocation is the point (Enscape: 17
seats / 35 users, cost spread across all); bulk add/remove users for any license.

- Per-license "Floating / Concurrent" section on license create/edit form
  (pool = `seats`, cost = `purchase_cost`).
- Master switch `floating_licenses_enabled` in Admin → Settings → General
  (new `settings` column via package migration).
- Durations made nullable/optional (new migration).
- Bulk add/remove routes + handlers; fixed licenses use real core seat
  checkout/checkin mechanisms.
- Core edits (minimal, guarded): `LicensesController` (2 lines),
  `SettingsController` (1 line), `licenses/edit.blade.php`,
  `licenses/view.blade.php`, `settings/general.blade.php`.
- Server runs v8.7.2 ≠ local repo → edits ported onto downloaded server copies
  (md5-verified) instead of overwriting.
- Tests: 72/72.

## 4. Rework round 2 — presentation + "master on = everything floats"

- Floating rows moved into the license **info panel** (License Type, Pool Size,
  Assigned Users, Available, Total Cost, Cost Per User + compact `+X` over-allocation label).
- Floating users listed inside the **Assigned** tab with over-allocation alert
  ("Over-allocated: 46 assigned / 35 seats (+11)").
- Bulk add/remove moved from panels to a **Bulk User Actions** dropdown in the
  license toolbar, opening dedicated form pages.
- Master switch ON ⇒ even fixed-seat licenses behave floating: lazy default
  config from seats/purchase_cost, `active_user` cost spread.
- `FloatingLicenseSync::configForLicense()` resolver; `BulkUserAssignment` support class.
- Tests: 86/86.

## 5. Calculation fixes (screenshot review)

- Licenses index `Avail`/`% Remaining` now subtract active floating allocations
  (negative allowed; bar clamped 0–100) — hunk in `LicensesTransformer`
  (2 grouped queries, no N+1).
- License view "Remaining" row overridden to floating availability (JS override
  keyed on DOM id `remaining` — locale caveat documented).
- Available tab: per-seat Checkout buttons suppressed for floating licenses
  (`LicenseSeatsTransformer` hunk forcing `available_actions.checkout=false`),
  plus an info note pointing to Bulk User Actions.
- Tests: 95/95 (2 transformer tests rewritten to hit the real API endpoint).

## 6. Checkout interception + per-user checkin

- `LicenseCheckoutController::store()` intercepted: floating license + user
  target ⇒ floating allocation instead of a seat record; capacity enforced via
  `PoolExhaustedException` (clean error flash).
- Checkout page shows floating-aware availability ("Rhino (-23 seats available)")
  + exhaustion callout when over-allocation is off.
- Per-row **Checkin** button in the Floating-Assigned Users table (release route,
  redirect back to license page).
- v8.7.2 port note: request fields are `assigned_to`/`asset_id` (develop uses
  `assigned_user`/`assigned_asset`); tests aligned to `assigned_to`.
- Tests: 105/105 (347 assertions).

## 7. Packaging & publishing

- Self-contained repo assembled at `snipe-it-floating-license-plugin/`:
  `package/` (byte-identical copy), `core-patches/` (9 patches + v8.7.2 variant
  02b + per-patch anchors README), `install.sh` (idempotent installer),
  README.md with AI-generated-code notice, install guides for git/manual/Docker,
  usage, API, permissions, uninstall.
- 8 real screenshots captured from the live server with puppeteer
  (temporary superuser `floatshot_tmp`, id 441, deleted afterward) into
  `docs/screenshots/01..08-*.png`.
- Published to `git@github.com:sanoj-m/SnipeIT-Floating-License-Plugin.git`,
  commit `2101233`, branch `main`. Auth via generated ed25519 deploy key
  (`~/.ssh/snipeit_plugin_deploy`) added as a repo deploy key with write access.
  Local git identity set repo-locally: `sanoj-m <sanoj-m@users.noreply.github.com>`.

## 8. Final verification

- Server package content diffed against local: identical.
- All 9 core patches confirmed present on the server; master switch = 1.
- Final server test run: **105/105 passing**.

# Session 2 — 2026-09-02 (follow-up fixes)

## 9. User-profile Licenses tab shows floating allocations

- Problem: floating assignments never appeared on the user's profile Licenses
  pane (only `license_seats` rows did).
- New `core-patches/10-user-view-blade.patch`: user profile Licenses tab loads
  the user's active `FloatingLicenseAllocation`s (master-switch guarded), adds a
  **License Type** column (Fixed Seats vs Floating / Concurrent), renders
  floating rows with allocated cost and a per-row Checkin (POST to
  `floating-licenses.allocations.release`), and counts them in the tab badge
  and transfer-button condition.
- Deployed to the server via base64-chunked upload (no SFTP subsystem), then
  `view:clear` + `optimize:clear`.
- Bug found on deploy: a `{{-- --}}` Blade comment inside an `@php` block
  caused a 500 parse error — replaced with `//` comments (patch corrected in
  commit `160f80b`).

## 10. Bulk checkin includes floating rows; floating users link to profiles

- Floating rows on the user profile now have bulk-select checkboxes with value
  `floating:<allocation_id>` (join count badge + select-all).
- New `core-patches/12-license-checkin-controller.patch`:
  `LicenseCheckinController::bulkCheckinSelected()` splits `floating:` ids from
  seat ids and releases them via `FloatingLicenseService::release()`, same
  authorization as the single-release handler (own allocation or
  `floating_licenses.release`).
- License view "Floating-Assigned Users" names now link to `users.show`.
- Also added `core-patches/11-phpunit-xml.patch` (FloatingLicenses testsuite
  registration, previously only on disk).

## 11. Fix: disabling floating now sticks

- Root cause: `FloatingLicenseSync::configForLicense()` lazily created AND
  persisted a config on first view while the master switch is on, so unchecking
  Floating in the edit form was undone on the next page view.
- Fix: a soft-deleted config now means "explicitly disabled" — the resolver
  returns null and does not recreate it; re-enabling revives the trashed row.
- Regression test `test_config_resolver_does_not_recreate_soft_deleted_config`;
  LicenseFormSyncTest 16/16 passing on the server.

## 12. Repo housekeeping

- Removed all 8 screenshots from README + `docs/screenshots/` (history still
  contains them; history rewrite offered but not requested).
- Patch count references updated to twelve across READMEs.
- Patch 07 regenerated to include newer license-view edits (Available tab
  hidden for floating licenses).
- `packages/floating-licenses` verified byte-identical to repo `package/`.

## Notes / caveats

- Root DB password and SSH root password were shared in chat (plaintext) —
  rotation recommended.
- Pre-existing server issue: `php artisan boost:update` composer script fails
  (unrelated to the addon).
- The "Remaining" row override is locale-dependent (DOM id from translation).
- Core patches must be re-applied after any Snipe-IT upgrade — see
  `core-patches/README.md` for anchors.
- `composer.json` changes on the server: path repository, `@dev` require,
  autoload-dev test mapping, `use-github-api=false`.
