<?php

namespace SnipeIt\FloatingLicenses\Services;

use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\License;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use SnipeIt\FloatingLicenses\Exceptions\InvalidAllocationException;
use SnipeIt\FloatingLicenses\Exceptions\PoolExhaustedException;
use SnipeIt\FloatingLicenses\Models\FloatingLicenseAllocation;
use SnipeIt\FloatingLicenses\Models\FloatingLicenseConfig;

class FloatingLicenseService
{
    /**
     * Allocate a pool slot to a user (and optionally an asset).
     *
     * Concurrency-safe: the config row is re-read with a row lock inside a
     * transaction before the active count is checked, so two simultaneous
     * requests cannot both take the last slot (unless over-allocation is on).
     *
     * @throws PoolExhaustedException When the pool is full and over-allocation is disabled.
     */
    public function allocate(FloatingLicenseConfig $config, User $user, ?Asset $asset = null, ?string $notes = null): FloatingLicenseAllocation
    {
        try {
            return DB::transaction(function () use ($config, $user, $asset, $notes) {
                /** @var FloatingLicenseConfig $lockedConfig */
                $lockedConfig = FloatingLicenseConfig::where('id', $config->id)->lockForUpdate()->firstOrFail();

                $active = FloatingLicenseAllocation::where('license_id', $lockedConfig->license_id)->active()->count();

                if ($active >= $lockedConfig->pool_size && ! $lockedConfig->allow_over_allocation) {
                    throw new PoolExhaustedException($lockedConfig);
                }

            $now = Carbon::now();

            $allocation = new FloatingLicenseAllocation;
            $allocation->license_id = $lockedConfig->license_id;
            $allocation->user_id = $user->id;
            $allocation->asset_id = $asset?->id;
            $allocation->status = FloatingLicenseAllocation::STATUS_ACTIVE;
            $allocation->allocated_at = $now;
            $allocation->last_seen_at = $now;
            // A null lease duration means the allocation never expires.
            $allocation->expires_at = $lockedConfig->lease_duration_minutes
                ? $now->copy()->addMinutes($lockedConfig->lease_duration_minutes)
                : null;
            $allocation->notes = $notes;
            $allocation->save();

            $this->writeAuditLog($lockedConfig->license_id, $user->id, 'floating.allocate',
                trans('floating-licenses::floating.log.allocate', ['id' => $allocation->id]));

            $this->recalculateCosts($lockedConfig);

            return $allocation->refresh();
            });
        } catch (PoolExhaustedException $e) {
            // Log the denial AFTER the transaction has rolled back, otherwise
            // the audit row would be rolled back along with the allocation.
            $active = FloatingLicenseAllocation::where('license_id', $e->config->license_id)->active()->count();

            $this->writeAuditLog($e->config->license_id, $user->id, 'floating.capacity_exceeded',
                trans('floating-licenses::floating.log.capacity_exceeded', [
                    'active' => $active,
                    'pool_size' => $e->config->pool_size,
                ]));

            throw $e;
        }
    }

    /**
     * Release an active allocation back to the pool.
     *
     * Idempotent-ish: only active allocations are released; anything else
     * throws so callers can't silently double-release.
     *
     * @throws InvalidAllocationException When the allocation is not active.
     */
    public function release(FloatingLicenseAllocation $allocation, ?User $actor = null): FloatingLicenseAllocation
    {
        if (! $allocation->isActive()) {
            throw new InvalidAllocationException(trans('floating-licenses::floating.error.not_active'));
        }

        $allocation->status = FloatingLicenseAllocation::STATUS_RELEASED;
        $allocation->released_at = Carbon::now();
        $allocation->save();

        $this->writeAuditLog($allocation->license_id, $allocation->user_id, 'floating.release',
            trans('floating-licenses::floating.log.release', ['id' => $allocation->id]), $actor);

        if ($config = $this->configFor($allocation)) {
            $this->recalculateCosts($config);
        }

        return $allocation;
    }

    /**
     * Record a heartbeat for an active allocation, extending its lease.
     *
     * @throws InvalidAllocationException When the allocation is not active.
     */
    public function heartbeat(FloatingLicenseAllocation $allocation): FloatingLicenseAllocation
    {
        if (! $allocation->isActive()) {
            throw new InvalidAllocationException(trans('floating-licenses::floating.error.not_active'));
        }

        $config = $this->configFor($allocation);
        $leaseMinutes = $config?->lease_duration_minutes ?? config('floating-licenses.lease_duration_minutes');

        $now = Carbon::now();
        $allocation->last_seen_at = $now;
        // Pools without a lease duration never expire; the heartbeat just
        // refreshes last_seen_at (kept for API compatibility).
        $allocation->expires_at = $leaseMinutes ? $now->copy()->addMinutes($leaseMinutes) : null;
        $allocation->save();

        if (config('floating-licenses.log_heartbeats', false)) {
            $this->writeAuditLog($allocation->license_id, $allocation->user_id, 'floating.heartbeat',
                trans('floating-licenses::floating.log.heartbeat', ['id' => $allocation->id]));
        }

        return $allocation;
    }

    /**
     * Administratively revoke an active allocation.
     *
     * @throws InvalidAllocationException When the allocation is not active.
     */
    public function revoke(FloatingLicenseAllocation $allocation, User $actor): FloatingLicenseAllocation
    {
        if (! $allocation->isActive()) {
            throw new InvalidAllocationException(trans('floating-licenses::floating.error.not_active'));
        }

        $allocation->status = FloatingLicenseAllocation::STATUS_REVOKED;
        $allocation->released_at = Carbon::now();
        $allocation->save();

        $this->writeAuditLog($allocation->license_id, $allocation->user_id, 'floating.revoke',
            trans('floating-licenses::floating.log.revoke', ['id' => $allocation->id]), $actor);

        if ($config = $this->configFor($allocation)) {
            $this->recalculateCosts($config);
        }

        return $allocation;
    }

    /**
     * Expire allocations whose lease has run out or that have gone idle.
     *
     * Only allocations whose pool config HAS durations set are touched:
     * allocations with a null lease never get an expires_at (so the past-lease
     * clause can't match them) and pools with a null idle timeout are skipped
     * in the idle clause.
     *
     * @return int The number of allocations that were expired.
     */
    public function expireStale(): int
    {
        $now = Carbon::now();
        $expired = 0;
        $affectedLicenseIds = [];

        FloatingLicenseAllocation::active()
            ->where(function ($query) use ($now) {
                $query->where('expires_at', '<', $now);

                foreach (FloatingLicenseConfig::whereNotNull('idle_timeout_minutes')->get() as $config) {
                    $query->orWhere(function ($idleQuery) use ($now, $config) {
                        $idleQuery->where('license_id', $config->license_id)
                            ->where('last_seen_at', '<', $now->copy()->subMinutes($config->idle_timeout_minutes));
                    });
                }
            })
            ->chunkById(100, function ($allocations) use (&$expired, &$affectedLicenseIds) {
                foreach ($allocations as $allocation) {
                    $allocation->status = FloatingLicenseAllocation::STATUS_EXPIRED;
                    $allocation->released_at = Carbon::now();
                    $allocation->save();

                    $this->writeAuditLog($allocation->license_id, $allocation->user_id, 'floating.expire',
                        trans('floating-licenses::floating.log.expire', ['id' => $allocation->id]));

                    $affectedLicenseIds[$allocation->license_id] = true;
                    $expired++;
                }
            });

        foreach (array_keys($affectedLicenseIds) as $licenseId) {
            if ($config = FloatingLicenseConfig::where('license_id', $licenseId)->first()) {
                $this->recalculateCosts($config);
            }
        }

        return $expired;
    }

    /**
     * Pool availability summary.
     *
     * @return array{pool_size: int, active: int, available: int, over_allocation_allowed: bool, over_allocated: bool, excess: int}
     */
    public function availability(FloatingLicenseConfig $config): array
    {
        $active = FloatingLicenseAllocation::where('license_id', $config->license_id)->active()->count();

        return [
            'pool_size' => $config->pool_size,
            'active' => $active,
            'available' => max(0, $config->pool_size - $active),
            'over_allocation_allowed' => (bool) $config->allow_over_allocation,
            // Informational indicator, independent of allow_over_allocation.
            'over_allocated' => $active > $config->pool_size,
            'excess' => max(0, $active - $config->pool_size),
        ];
    }

    /**
     * The cost of a single pool slot: total_cost divided by pool_size.
     * Returns 0 when pool_size or total_cost is empty/zero (0-guard).
     */
    public function costPerSlot(FloatingLicenseConfig $config): float
    {
        if (! $config->total_cost || $config->pool_size < 1) {
            return 0.0;
        }

        return round(((float) $config->total_cost) / $config->pool_size, 2);
    }

    /**
     * The live per-user cost for display: in active_user mode the total cost
     * is spread across the currently active allocations (0 when none are
     * active); in pool_slot mode each allocation carries the fixed per-slot
     * price. Mirrors recalculateCosts() but computed on demand.
     */
    public function costPerUser(FloatingLicenseConfig $config): float
    {
        if ($config->cost_mode === FloatingLicenseConfig::COST_MODE_ACTIVE_USER) {
            $active = FloatingLicenseAllocation::where('license_id', $config->license_id)->active()->count();

            if (! $config->total_cost || $active < 1) {
                return 0.0;
            }

            return round(((float) $config->total_cost) / $active, 2);
        }

        return $this->costPerSlot($config);
    }

    /**
     * Recalculate the allocated_cost snapshot on every active allocation.
     *
     * - `pool_slot` mode: each active allocation is charged the fixed
     *   per-slot price (total_cost / pool_size), regardless of how many
     *   allocations are currently active.
     * - `active_user` mode: the total_cost is split evenly across the
     *   currently active allocations (total_cost / active count), so the
     *   per-allocation cost shrinks as more users share the pool.
     */
    public function recalculateCosts(FloatingLicenseConfig $config): void
    {
        $activeAllocations = FloatingLicenseAllocation::where('license_id', $config->license_id)->active()->get();

        if ($config->cost_mode === FloatingLicenseConfig::COST_MODE_ACTIVE_USER) {
            $count = $activeAllocations->count();
            $cost = ($config->total_cost && $count > 0) ? round(((float) $config->total_cost) / $count, 2) : 0.0;
        } else {
            $cost = $this->costPerSlot($config);
        }

        foreach ($activeAllocations as $allocation) {
            if ((float) $allocation->allocated_cost !== $cost) {
                $allocation->allocated_cost = $cost;
                $allocation->save();
            }
        }
    }

    /**
     * Look up the pool config for an allocation's license.
     */
    protected function configFor(FloatingLicenseAllocation $allocation): ?FloatingLicenseConfig
    {
        return FloatingLicenseConfig::where('license_id', $allocation->license_id)->first();
    }

    /**
     * Write an Actionlog entry mirroring how Loggable writes history rows.
     *
     * Note: Actionlog::logaction() cannot be used here because it casts the
     * action type through the App\Enums\ActionType enum, which has no
     * floating.* cases. The columns logaction() would have set are assigned
     * directly instead.
     */
    protected function writeAuditLog(int $licenseId, ?int $targetUserId, string $actionType, ?string $note = null, ?User $actor = null): Actionlog
    {
        $log = new Actionlog;
        $log->item_type = License::class;
        $log->item_id = $licenseId;
        $log->target_type = User::class;
        $log->target_id = $targetUserId;
        $log->note = $note;
        $log->created_by = $actor?->id ?? auth()->id();
        $log->company_id = License::find($licenseId)?->company_id;
        $log->action_date = date('Y-m-d H:i:s');
        $log->action_type = $actionType;
        $log->remote_ip = request()->ip();
        $log->user_agent = request()->header('User-Agent');
        $log->action_source = $log->determineActionSource();
        $log->save();

        return $log;
    }
}
