<?php

namespace SnipeIt\FloatingLicenses\Support;

use App\Events\CheckoutableCheckedIn;
use App\Events\CheckoutableCheckedOut;
use App\Models\License;
use App\Models\LicenseSeat;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use SnipeIt\FloatingLicenses\Exceptions\InvalidAllocationException;
use SnipeIt\FloatingLicenses\Exceptions\PoolExhaustedException;
use SnipeIt\FloatingLicenses\Models\FloatingLicenseAllocation;
use SnipeIt\FloatingLicenses\Services\FloatingLicenseService;

class BulkUserAssignment
{
    public function __construct(public readonly FloatingLicenseService $service) {}

    /**
     * Bulk-assign a license to a set of users.
     *
     * When the master switch is on, EVERY license behaves floating:
     * FloatingLicenseSync::configForLicense() lazily creates a default pool
     * (seats = pool size, active_user cost spread, over-allocation on), so
     * each user gets an active allocation. Only when the master switch is off
     * and no pool config exists does this fall back to the exact core
     * seat-checkout mechanism (free seat claimed under a row lock,
     * CheckoutableCheckedOut fired — same as
     * LicenseCheckoutController::bulkFulfillStore()).
     *
     * @param  int[]  $userIds
     * @return array{added: int, skipped: int, failed: int}
     */
    public function addUsers(License $license, array $userIds): array
    {
        $config = FloatingLicenseSync::configForLicense($license);

        $added = 0;
        $skipped = 0;
        $failed = 0;

        foreach (array_unique($userIds) as $userId) {
            $user = User::find($userId);

            if (! $user) {
                $failed++;

                continue;
            }

            if ($config) {
                $alreadyActive = FloatingLicenseAllocation::where('license_id', $license->id)
                    ->where('user_id', $user->id)
                    ->active()
                    ->exists();

                if ($alreadyActive) {
                    $skipped++;

                    continue;
                }

                try {
                    $this->service->allocate($config, $user);
                    $added++;
                } catch (PoolExhaustedException) {
                    $failed++;
                }

                continue;
            }

            // Fixed-seat fallback (master switch off): core seat checkout.
            $alreadyAssigned = LicenseSeat::where('license_id', $license->id)
                ->where('assigned_to', $user->id)
                ->whereNull('deleted_at')
                ->exists();

            if ($alreadyAssigned) {
                $skipped++;

                continue;
            }

            $seat = DB::transaction(function () use ($license, $user): ?LicenseSeat {
                $seat = $license->freeSeat(lock: true);

                if (! $seat) {
                    return null;
                }

                $seat->assigned_to = $user->id;
                $seat->created_by = auth()->id();

                return $seat->save() ? $seat : null;
            });

            if (! $seat) {
                $failed++;

                continue;
            }

            event(new CheckoutableCheckedOut($seat, $user, auth()->user(), trans('floating-licenses::floating.log.bulk_checkout')));
            $added++;
        }

        return ['added' => $added, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * Bulk-remove a license from a set of users.
     *
     * Handles the mixed state: each user's active floating allocation is
     * released AND any core seat checked out to them is checked back in
     * (mirroring LicenseCheckinController::bulkCheckinSelected()), so a
     * license that accumulated seat checkouts before floating took over is
     * cleaned up by the same action.
     *
     * @param  int[]  $userIds
     * @return array{removed: int, skipped: int, failed: int}
     */
    public function removeUsers(License $license, array $userIds): array
    {
        $userIds = array_unique($userIds);

        $removed = 0;
        $failed = 0;
        $foundUserIds = [];

        $allocations = FloatingLicenseAllocation::where('license_id', $license->id)
            ->whereIn('user_id', $userIds)
            ->active()
            ->get();

        foreach ($allocations as $allocation) {
            $foundUserIds[$allocation->user_id] = true;

            try {
                $this->service->release($allocation, auth()->user());
                $removed++;
            } catch (InvalidAllocationException) {
                $failed++;
            }
        }

        $seats = LicenseSeat::where('license_id', $license->id)
            ->whereIn('assigned_to', $userIds)
            ->whereNull('deleted_at')
            ->with('user')
            ->get();

        foreach ($seats as $seat) {
            $foundUserIds[$seat->assigned_to] = true;
            $target = $seat->user;

            $seat->assigned_to = null;
            $seat->asset_id = null;
            if (! $license->reassignable) {
                $seat->unreassignable_seat = true;
            }

            if ($seat->save()) {
                event(new CheckoutableCheckedIn($seat, $target, auth()->user(), trans('floating-licenses::floating.log.bulk_checkin')));
                $removed++;
            } else {
                $failed++;
            }
        }

        return [
            'removed' => $removed,
            'skipped' => count(array_diff($userIds, array_keys($foundUserIds))),
            'failed' => $failed,
        ];
    }

    /**
     * Users currently holding the license via an active floating allocation.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function floatingAssignedUsers(License $license): \Illuminate\Support\Collection
    {
        return FloatingLicenseAllocation::where('license_id', $license->id)
            ->active()
            ->with('user')
            ->get()
            ->pluck('user')
            ->filter()
            ->unique('id')
            ->values();
    }

    /**
     * Users currently holding the license via a core seat checkout.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function seatAssignedUsers(License $license): \Illuminate\Support\Collection
    {
        return LicenseSeat::where('license_id', $license->id)
            ->whereNotNull('assigned_to')
            ->whereNull('deleted_at')
            ->with('user')
            ->get()
            ->pluck('user')
            ->filter()
            ->unique('id')
            ->values();
    }
}
