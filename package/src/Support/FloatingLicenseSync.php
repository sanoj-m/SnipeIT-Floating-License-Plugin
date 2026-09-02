<?php

namespace SnipeIt\FloatingLicenses\Support;

use App\Models\License;
use App\Models\Setting;
use Illuminate\Http\Request;
use SnipeIt\FloatingLicenses\Models\FloatingLicenseConfig;
use SnipeIt\FloatingLicenses\Services\FloatingLicenseService;

class FloatingLicenseSync
{
    /**
     * Whether the addon's master switch (Admin > Settings > General) is on.
     */
    public static function isEnabled(): bool
    {
        return (Setting::getSettings()?->floating_licenses_enabled ?? '0') == '1';
    }

    /**
     * Resolve the floating pool config for a license.
     *
     * Returns the persisted config when one exists. When the master switch is
     * on and no config exists yet, a default config is lazily created AND
     * persisted from the license's own attributes (seats = pool size,
     * purchase_cost = total cost, active_user cost spread, over-allocation
     * on) — master switch on means every license behaves floating. Returns
     * null when the master switch is off and no config exists.
     */
    public static function configForLicense(License $license): ?FloatingLicenseConfig
    {
        $config = FloatingLicenseConfig::where('license_id', $license->id)->first();

        if ($config) {
            return $config;
        }

        if (! self::isEnabled()) {
            return null;
        }

        return FloatingLicenseConfig::create([
            'license_id' => $license->id,
            'pool_size' => (int) $license->seats,
            'total_cost' => is_numeric($license->purchase_cost) ? (float) $license->purchase_cost : null,
            'cost_mode' => FloatingLicenseConfig::COST_MODE_ACTIVE_USER,
            'allow_over_allocation' => true,
            'lease_duration_minutes' => null,
            'idle_timeout_minutes' => null,
        ]);
    }

    /**
     * Sync a license's floating config from the license create/edit form.
     *
     * The license's own attributes are the source of truth: seats = pool size,
     * purchase_cost = total cost. Called from LicensesController::store() and
     * ::update() right after the license is saved; a no-op when the master
     * switch is off.
     */
    public static function syncFromRequest(License $license, Request $request): void
    {
        if (! self::isEnabled()) {
            return;
        }

        if ($request->boolean('floating_enabled')) {
            /** @var FloatingLicenseConfig $config */
            $config = FloatingLicenseConfig::withTrashed()->firstOrNew(['license_id' => $license->id]);

            $config->pool_size = (int) $license->seats;
            $config->total_cost = is_numeric($license->purchase_cost) ? (float) $license->purchase_cost : null;
            $config->cost_mode = in_array($request->input('floating_cost_mode'), [
                FloatingLicenseConfig::COST_MODE_POOL_SLOT,
                FloatingLicenseConfig::COST_MODE_ACTIVE_USER,
            ], true)
                ? $request->input('floating_cost_mode')
                : ($config->cost_mode ?: FloatingLicenseConfig::COST_MODE_POOL_SLOT);

            // The license form always posts this field (hidden 0 + checkbox 1),
            // so absence means a non-form caller; then keep the stored value,
            // defaulting a fresh config to on (the common floating use case).
            $config->allow_over_allocation = $request->has('floating_allow_over_allocation')
                ? $request->boolean('floating_allow_over_allocation')
                : (bool) ($config->allow_over_allocation ?? true);

            $config->deleted_at = null;
            $config->save();

            app(FloatingLicenseService::class)->recalculateCosts($config);

            return;
        }

        // Unchecked: remove the config, but never strand active allocations.
        $config = FloatingLicenseConfig::where('license_id', $license->id)->first();

        if (! $config) {
            return;
        }

        if ($config->activeAllocations()->count() > 0) {
            session()->flash('warning', trans('floating-licenses::floating.message.kept_active_allocations'));

            return;
        }

        $config->delete();
    }
}
