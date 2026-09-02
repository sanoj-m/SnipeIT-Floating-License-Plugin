<?php

namespace SnipeIt\FloatingLicenses\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\License;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use SnipeIt\FloatingLicenses\Exceptions\InvalidAllocationException;
use SnipeIt\FloatingLicenses\Exceptions\PoolExhaustedException;
use SnipeIt\FloatingLicenses\Models\FloatingLicenseAllocation;
use SnipeIt\FloatingLicenses\Models\FloatingLicenseConfig;
use SnipeIt\FloatingLicenses\Services\FloatingLicenseService;
use SnipeIt\FloatingLicenses\Support\FloatingLicenseSync;

class FloatingLicenseApiController extends Controller
{
    public function __construct(public readonly FloatingLicenseService $service)
    {
        // Master switch (Admin > Settings > General) gates the API too.
        $this->middleware(function ($request, $next) {
            abort_unless(FloatingLicenseSync::isEnabled(), 403);

            return $next($request);
        });
    }

    /**
     * Resolve the floating pool config for a core License id, or null when
     * floating licensing is not enabled on that license.
     */
    protected function configForLicense(int $licenseId): ?FloatingLicenseConfig
    {
        return FloatingLicenseConfig::where('license_id', $licenseId)->first();
    }

    /**
     * Allocate a pool slot for the given license to a user (and optional asset).
     */
    public function allocate(Request $request, License $license): JsonResponse
    {
        $this->authorize('floating_licenses.allocate');

        // Validate manually instead of $request->validate(): Snipe-IT's
        // exception handler converts a bubbling ValidationException into a
        // 200 error envelope, and this endpoint must return a real 422.
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'asset_id' => 'nullable|integer|exists:assets,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $config = $this->configForLicense($license->id);

        if (! $config) {
            return response()->json(
                Helper::formatStandardApiResponse('error', null, trans('floating-licenses::floating.error.no_config')),
                404
            );
        }

        $user = User::findOrFail($validated['user_id']);
        $asset = isset($validated['asset_id']) ? Asset::find($validated['asset_id']) : null;

        try {
            $allocation = $this->service->allocate($config, $user, $asset, $validated['notes'] ?? null);
        } catch (PoolExhaustedException) {
            return response()->json(Helper::formatStandardApiResponse('error', null, trans('floating-licenses::floating.error.pool_exhausted')), 422);
        }

        return response()->json(Helper::formatStandardApiResponse('success', $allocation, trans('floating-licenses::floating.message.allocated')));
    }

    /**
     * Heartbeat an active allocation, extending its lease.
     */
    public function heartbeat(FloatingLicenseAllocation $allocation): JsonResponse
    {
        $user = request()->user();

        if ($allocation->user_id !== $user->id) {
            $this->authorize('floating_licenses.release');
        } else {
            $this->authorize('floating_licenses.allocate');
        }

        try {
            $allocation = $this->service->heartbeat($allocation);
        } catch (InvalidAllocationException) {
            return response()->json(Helper::formatStandardApiResponse('error', null, trans('floating-licenses::floating.error.not_active')), 422);
        }

        return response()->json(Helper::formatStandardApiResponse('success', $allocation, trans('floating-licenses::floating.message.heartbeat')));
    }

    /**
     * Release an allocation back to the pool.
     */
    public function release(FloatingLicenseAllocation $allocation): JsonResponse
    {
        $user = request()->user();

        if ($allocation->user_id !== $user->id) {
            $this->authorize('floating_licenses.release');
        } else {
            $this->authorize('floating_licenses.allocate');
        }

        try {
            $allocation = $this->service->release($allocation, $user);
        } catch (InvalidAllocationException) {
            return response()->json(Helper::formatStandardApiResponse('error', null, trans('floating-licenses::floating.error.not_active')), 422);
        }

        return response()->json(Helper::formatStandardApiResponse('success', $allocation, trans('floating-licenses::floating.message.released')));
    }

    /**
     * Availability summary for a license's floating pool.
     */
    public function availability(License $license): JsonResponse
    {
        $this->authorize('floating_licenses.view');

        $config = $this->configForLicense($license->id);

        if (! $config) {
            return response()->json(
                Helper::formatStandardApiResponse('error', null, trans('floating-licenses::floating.error.no_config')),
                404
            );
        }

        return response()->json(Helper::formatStandardApiResponse('success', $this->service->availability($config), trans('floating-licenses::floating.message.availability')));
    }
}
