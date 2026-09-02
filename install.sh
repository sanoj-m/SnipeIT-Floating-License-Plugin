#!/usr/bin/env bash
#
# install.sh — helper to install the Snipe-IT Floating License plugin.
#
# Usage:
#   ./install.sh [/path/to/snipe-it] [--force]
#
# What it does (idempotent where possible):
#   1. Verifies the target looks like a Snipe-IT root (artisan present).
#   2. Copies package/ to <snipe-it>/packages/floating-licenses
#      (refuses to overwrite an existing directory unless --force).
#   3. Patches composer.json (path repository, require, autoload-dev test
#      mapping) via PHP JSON manipulation — skips entries already present.
#   4. Tries `git apply` for the core patches; on failure prints a pointer
#      to core-patches/README.md for the small manual edits.
#
# What it deliberately does NOT do: run composer, artisan migrate, or any
# other state-changing command — those are printed as manual steps at the end.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SNIPE_ROOT="${1:-.}"
FORCE=0
for arg in "$@"; do
    [ "$arg" = "--force" ] && FORCE=1
done

SNIPE_ROOT="$(cd "$SNIPE_ROOT" && pwd)"

echo "==> Snipe-IT root: $SNIPE_ROOT"

if [ ! -f "$SNIPE_ROOT/artisan" ]; then
    echo "ERROR: no artisan found in $SNIPE_ROOT — pass the Snipe-IT root as the first argument." >&2
    exit 1
fi

# --- 1. Copy the package ---------------------------------------------------
TARGET_PKG="$SNIPE_ROOT/packages/floating-licenses"
if [ -d "$TARGET_PKG" ]; then
    if [ "$FORCE" = "1" ]; then
        echo "==> Replacing existing $TARGET_PKG (--force)"
        rm -rf "$TARGET_PKG"
        cp -r "$SCRIPT_DIR/package" "$TARGET_PKG"
    else
        echo "==> $TARGET_PKG already exists — leaving it untouched (use --force to replace)"
    fi
else
    cp -r "$SCRIPT_DIR/package" "$TARGET_PKG"
    echo "==> Copied package to $TARGET_PKG"
fi

# --- 2. Patch composer.json ------------------------------------------------
php -r '
$root = $argv[1];
$file = $root . "/composer.json";
$data = json_decode(file_get_contents($file), true);
if (! is_array($data)) { fwrite(STDERR, "ERROR: could not parse composer.json\n"); exit(1); }

$changed = false;

// path repository
$repos = $data["repositories"] ?? [];
$found = false;
foreach ($repos as $repo) {
    if (($repo["type"] ?? "") === "path" && ($repo["url"] ?? "") === "packages/floating-licenses") { $found = true; break; }
}
if (! $found) {
    $repos[] = ["type" => "path", "url" => "packages/floating-licenses", "options" => ["symlink" => true]];
    $data["repositories"] = $repos;
    $changed = true;
    echo "  + repositories: path packages/floating-licenses\n";
}

// require
if (! isset($data["require"]["snipe-it/floating-licenses"])) {
    $data["require"]["snipe-it/floating-licenses"] = "@dev";
    $changed = true;
    echo "  + require: snipe-it/floating-licenses @dev\n";
}

// autoload-dev test mapping
if (! isset($data["autoload-dev"]["psr-4"]["SnipeIt\\FloatingLicenses\\Tests\\"])) {
    $data["autoload-dev"]["psr-4"]["SnipeIt\\FloatingLicenses\\Tests\\"] = "packages/floating-licenses/tests/";
    $changed = true;
    echo "  + autoload-dev: SnipeIt\\FloatingLicenses\\Tests\\\n";
}

if ($changed) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    echo "==> composer.json updated (formatting normalized to JSON pretty-print; review with git diff)\n";
} else {
    echo "==> composer.json already wired — nothing to change\n";
}
' "$SNIPE_ROOT"

# --- 3. Apply core patches -------------------------------------------------
if [ -d "$SNIPE_ROOT/.git" ]; then
    PATCH_OK=1
    for patch in "$SCRIPT_DIR"/core-patches/*.patch; do
        name="$(basename "$patch")"
        # 02b is the v8.7.2 alternative to 02 — never apply both.
        if [ "$name" = "02b-license-checkout-v8.7.2.patch" ]; then
            echo "==> Skipping $name (alternative variant — see core-patches/README.md, apply 02 OR 02b)"
            continue
        fi
        if git -C "$SNIPE_ROOT" apply --check "$patch" 2>/dev/null; then
            git -C "$SNIPE_ROOT" apply "$patch"
            echo "==> Applied $name"
        else
            echo "!! Could not apply $name cleanly — see core-patches/README.md for the manual edit (anchor listed there)"
            PATCH_OK=0
        fi
    done
    if grep -q "assigned_to" "$SNIPE_ROOT/app/Http/Controllers/Licenses/LicenseCheckoutController.php" 2>/dev/null \
        && ! grep -q "assigned_user" "$SNIPE_ROOT/app/Http/Controllers/Licenses/LicenseCheckoutController.php" 2>/dev/null; then
        echo "!! NOTE: your LicenseCheckoutController.php uses the v8.x field names (assigned_to/asset_id)."
        echo "!! If 02-license-checkout-controller.patch failed, apply core-patches/02b-license-checkout-v8.7.2.patch instead."
    fi
else
    echo "!! $SNIPE_ROOT is not a git checkout — apply the core edits manually (see core-patches/README.md)"
    PATCH_OK=0
fi

# --- 4. Remaining manual steps ---------------------------------------------
cat <<'EOF'

==> Remaining steps (run from the Snipe-IT root):

    composer update snipe-it/floating-licenses --no-interaction
    # If composer fails on github.com authentication:
    #   composer config use-github-api false
    #   composer update snipe-it/floating-licenses --no-interaction

    php artisan migrate --force
    php artisan optimize:clear

    # Optional, only needed if you configure lease durations on pools:
    #   add to app/Console/Kernel.php schedule():
    #   $schedule->command('floating-licenses:expire')->everyFiveMinutes();

Then enable the addon under Admin → Settings → General → "Floating Licenses
(addon)" and grant the floating_licenses.* permissions to your groups.

EOF

[ "${PATCH_OK:-1}" = "1" ] || echo "==> Some patches need manual application — read core-patches/README.md before continuing."
