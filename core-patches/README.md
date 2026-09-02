# Core patches

The plugin needs nine small, guarded edits to Snipe-IT core files. Each patch
is a real unified diff generated from a working Snipe-IT tree and applies with:

```bash
cd /path/to/snipe-it
git apply core-patches/01-licenses-controller.patch
# ... etc
```

If `git apply` fails (different core version), each edit below is small enough
to make by hand — the anchor text tells you exactly where. All PHP edits are
wrapped in `// [floating-licenses addon] BEGIN` / `END` comments so they are
easy to find and re-apply after a Snipe-IT upgrade.

## Patch list

| Patch | File | What it does | Anchor |
|---|---|---|---|
| `01-licenses-controller.patch` | `app/Http/Controllers/Licenses/LicensesController.php` | Adds one line `\SnipeIt\FloatingLicenses\Support\FloatingLicenseSync::syncFromRequest($license, $request);` at the top of the `if ($license->save())` block in **both** `store()` and `update()`. | `if ($license->save()) {` (two occurrences) |
| `02-license-checkout-controller.patch` | `app/Http/Controllers/Licenses/LicenseCheckoutController.php` | **develop variant.** Intercepts single checkout when the license has a floating pool: allocates a pool slot via `FloatingLicenseService::allocate()` instead of a seat record; `PoolExhaustedException` → redirect back with error. Uses request fields `assigned_user` / `assigned_asset`. | Right after `$this->authorize('checkout', $license);` in `store()` |
| `02b-license-checkout-v8.7.2.patch` | same file | **v8.7.2 variant of the same hunk** — identical logic but reads `assigned_to` / `asset_id` (the v8.x field names). Apply **either** 02 **or** 02b: check which field names your file uses with `grep -n "assigned_to" app/Http/Controllers/Licenses/LicenseCheckoutController.php` — if `assigned_to` appears in `store()`, apply 02b (by hand if the context lines don't match; the `+` lines are the complete code). | Same anchor as 02 |
| `03-settings-controller.patch` | `app/Http/Controllers/SettingsController.php` | Adds `$setting->floating_licenses_enabled = $request->input('floating_licenses_enabled', '0');` to `postSettings()`. | After `$setting->shortcuts_enabled = $request->input('shortcuts_enabled', '0');` |
| `04-licenses-transformer.patch` | `app/Http/Transformers/LicensesTransformer.php` | End of `transformLicenses()`: two grouped queries (no N+1) recompute `free_seats_count` / `remaining` (= seats − active floating allocations, may go negative) and `percent_remaining` (clamped 0-100) for licenses with a floating pool. Master switch off → untouched. | Before `return (new DatatablesTransformer)->transformDatatables($array, $total);` in `transformLicenses()` |
| `05-license-seats-transformer.patch` | `app/Http/Transformers/LicenseSeatsTransformer.php` | In `transformLicenseSeats()`: one query finds which of the page's licenses are floating; their seats get `available_actions.checkout = false` and `user_can_checkout = false`, which suppresses the per-seat Checkout button (rendered client-side by `licenseSeatInOutFormatter`). | The `foreach ($seats as $seat)` loop in `transformLicenseSeats()` |
| `06-license-edit-blade.patch` | `resources/views/licenses/edit.blade.php` | "Floating / Concurrent" section (enable checkbox, cost-mode select, over-allocation checkbox) before the form footer, guarded by the master switch. | After the notes `x-form.row`, before `<x-slot:customfooter>` |
| `07-license-view-blade.patch` | `resources/views/licenses/view.blade.php` | The big one: floating info rows in the info panel (License Type / Pool Size / Assigned Users / Available / Total Cost / Cost Per User), floating-assigned users table with per-row Checkin buttons + over-allocation alert in the Assigned tab, "Bulk User Actions" dropdown in the buttons slot, Available-tab info callout, and the `floating-remaining-override` script that corrects the core "Remaining" row. All guarded by the master switch. | Top of `@section('content')`, the `seats`/`available` tab panes, the `x-info-panel` default slot and `buttons` slot, before `@endsection` |
| `08-license-checkout-blade.patch` | `resources/views/licenses/checkout.blade.php` | Checkout page header shows pool availability (`pool − active`) instead of seat count when floating; warning callout when the pool is exhausted and over-allocation is off. | Top of `@section('content')`; the `x-box header=` line |
| `09-settings-general-blade.patch` | `resources/views/settings/general.blade.php` | One `x-form.checkbox-row` named `floating_licenses_enabled` ("Floating Licenses (addon)") in General settings. | After the `shortcuts_enabled` checkbox-row |

## If git apply fails

Every patch is small. Open the `.patch` file, find the anchor line listed
above in your copy of the core file, and insert the `+` lines (without the
leading `+`) at that spot. Blade hunks can be placed with a little latitude —
they are self-contained `@if`-guarded blocks.

## Re-applying after a Snipe-IT upgrade

Take the upstream version of any conflicting file, then re-apply the patch
(or the manual edit) for that file. The `BEGIN`/`END` markers and this table
are the map.
