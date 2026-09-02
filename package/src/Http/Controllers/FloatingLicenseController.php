<?php

namespace SnipeIt\FloatingLicenses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\License;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SnipeIt\FloatingLicenses\Exceptions\InvalidAllocationException;
use SnipeIt\FloatingLicenses\Exceptions\PoolExhaustedException;
use SnipeIt\FloatingLicenses\Models\FloatingLicenseAllocation;
use SnipeIt\FloatingLicenses\Models\FloatingLicenseConfig;
use SnipeIt\FloatingLicenses\Services\FloatingLicenseService;
use SnipeIt\FloatingLicenses\Support\BulkUserAssignment;
use SnipeIt\FloatingLicenses\Support\FloatingLicenseSync;

class FloatingLicenseController extends Controller
{
    public function __construct(public readonly FloatingLicenseService $service)
    {
        // Master switch (Admin > Settings > General) gates all web routes.
        $this->middleware(function ($request, $next) {
            abort_unless(FloatingLicenseSync::isEnabled(), 403);

            return $next($request);
        });
    }

    /**
     * List all floating license pools with their availability stats.
     */
    public function index(): View
    {
        $this->authorize('floating_licenses.view');

        $configs = FloatingLicenseConfig::with('license')->get();

        $stats = [];
        foreach ($configs as $config) {
            $stats[$config->id] = $this->service->availability($config);
            $stats[$config->id]['cost_per_slot'] = $this->service->costPerSlot($config);
        }

        return view('floating-licenses::index', compact('configs', 'stats'));
    }

    /**
     * Show a single floating license pool.
     */
    public function show(FloatingLicenseConfig $config): View
    {
        $this->authorize('floating_licenses.view');

        $config->load('license');

        $availability = $this->service->availability($config);
        $costPerSlot = $this->service->costPerSlot($config);
        $activeAllocations = $config->activeAllocations()->with(['user', 'asset'])->orderBy('allocated_at', 'desc')->get();

        $history = collect();
        if (Gate::allows('floating_licenses.history')) {
            $history = $config->allocations()->with('user')->orderBy('created_at', 'desc')->limit(50)->get();
        }

        return view('floating-licenses::show', compact('config', 'availability', 'costPerSlot', 'activeAllocations', 'history'));
    }

    /**
     * Form to enable floating licensing on an existing license.
     */
    public function create(): View
    {
        $this->authorize('floating_licenses.manage');

        $configuredLicenseIds = FloatingLicenseConfig::withTrashed()->pluck('license_id');
        $licenses = License::whereNotIn('id', $configuredLicenseIds)->orderBy('name')->get(['id', 'name']);

        return view('floating-licenses::create', compact('licenses'));
    }

    /**
     * Enable floating licensing on an existing license.
     */
    public function store(Request $request, License $license): RedirectResponse
    {
        $this->authorize('floating_licenses.manage');

        $validated = $request->validate([
            'pool_size' => 'required|integer|min:1',
            'total_cost' => 'nullable|numeric|min:0',
            'cost_mode' => 'nullable|in:pool_slot,active_user',
            'allow_over_allocation' => 'nullable|boolean',
            'lease_duration_minutes' => 'nullable|integer|min:1',
            'idle_timeout_minutes' => 'nullable|integer|min:1',
        ]);

        $config = FloatingLicenseConfig::withTrashed()->firstOrNew(['license_id' => $license->id]);
        $config->fill($validated);
        $config->cost_mode = $validated['cost_mode'] ?? config('floating-licenses.cost_mode', 'pool_slot');
        $config->allow_over_allocation = $request->boolean('allow_over_allocation', config('floating-licenses.allow_over_allocation', false));
        // Durations are optional; null means the pool never expires/idle-reclaims.
        $config->lease_duration_minutes = $validated['lease_duration_minutes'] ?? null;
        $config->idle_timeout_minutes = $validated['idle_timeout_minutes'] ?? null;
        $config->deleted_at = null;
        $config->save();

        $this->service->recalculateCosts($config);

        return redirect()->route('floating-licenses.show', $config)
            ->with('success', trans('floating-licenses::floating.message.enabled'));
    }

    /**
     * Edit form for a pool configuration.
     */
    public function edit(FloatingLicenseConfig $config): View
    {
        $this->authorize('floating_licenses.manage');

        $config->load('license');

        return view('floating-licenses::edit', compact('config'));
    }

    /**
     * Update a pool configuration.
     */
    public function update(Request $request, FloatingLicenseConfig $config): RedirectResponse
    {
        $this->authorize('floating_licenses.manage');

        $validated = $request->validate([
            'pool_size' => 'required|integer|min:1',
            'total_cost' => 'nullable|numeric|min:0',
            'cost_mode' => 'required|in:pool_slot,active_user',
            'allow_over_allocation' => 'nullable|boolean',
            'lease_duration_minutes' => 'nullable|integer|min:1',
            'idle_timeout_minutes' => 'nullable|integer|min:1',
        ]);

        $config->fill($validated);
        $config->allow_over_allocation = $request->boolean('allow_over_allocation');
        $config->save();

        $this->service->recalculateCosts($config);

        return redirect()->route('floating-licenses.show', $config)
            ->with('success', trans('floating-licenses::floating.message.updated'));
    }

    /**
     * Disable floating licensing for a license (only when no active allocations).
     */
    public function destroy(FloatingLicenseConfig $config): RedirectResponse
    {
        $this->authorize('floating_licenses.manage');

        if ($config->activeAllocations()->count() > 0) {
            return redirect()->route('floating-licenses.show', $config)
                ->with('error', trans('floating-licenses::floating.error.active_allocations'));
        }

        $config->delete();

        return redirect()->route('floating-licenses.index')
            ->with('success', trans('floating-licenses::floating.message.disabled'));
    }

    /**
     * Allocate a pool slot to a user (web form).
     */
    public function allocate(Request $request, FloatingLicenseConfig $config): RedirectResponse
    {
        $this->authorize('floating_licenses.allocate');

        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'asset_id' => 'nullable|integer|exists:assets,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        $user = User::findOrFail($validated['user_id']);
        $asset = isset($validated['asset_id']) ? Asset::find($validated['asset_id']) : null;

        try {
            $this->service->allocate($config, $user, $asset, $validated['notes'] ?? null);
        } catch (PoolExhaustedException) {
            return redirect()->route('floating-licenses.show', $config)
                ->with('error', trans('floating-licenses::floating.error.pool_exhausted'));
        }

        return redirect()->route('floating-licenses.show', $config)
            ->with('success', trans('floating-licenses::floating.message.allocated'));
    }

    /**
     * Release an allocation (own allocation or with release permission).
     *
     * Redirects back to the referer when one is present (e.g. the license
     * view page's per-row checkin button), falling back to the pool page.
     */
    public function release(Request $request, FloatingLicenseAllocation $allocation): RedirectResponse
    {
        $user = auth()->user();

        if ($allocation->user_id !== $user->id) {
            $this->authorize('floating_licenses.release');
        } else {
            $this->authorize('floating_licenses.allocate');
        }

        try {
            $this->service->release($allocation, $user);
        } catch (InvalidAllocationException) {
            return redirect()->route('floating-licenses.index')
                ->with('error', trans('floating-licenses::floating.error.not_active'));
        }

        $config = FloatingLicenseConfig::where('license_id', $allocation->license_id)->first();

        $fallback = $config
            ? route('floating-licenses.show', $config)
            : route('floating-licenses.index');

        return redirect()->to($request->headers->get('referer') ?: $fallback)
            ->with('success', trans('floating-licenses::floating.message.released'));
    }

    /**
     * Bulk-add form: multi-select of users, posting to the POST bulk-add route.
     */
    public function bulkAddForm(License $license): View
    {
        $this->authorize('floating_licenses.allocate');

        $users = User::orderBy('first_name')->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'username', 'display_name']);

        return view('floating-licenses::bulk-add', compact('license', 'users'));
    }

    /**
     * Bulk-remove form: checkbox list of currently assigned users (floating
     * allocations and core seat checkouts listed as separate groups).
     */
    public function bulkRemoveForm(License $license, BulkUserAssignment $bulk): View
    {
        $this->authorize('floating_licenses.release');

        return view('floating-licenses::bulk-remove', [
            'license' => $license,
            'floatingUsers' => $bulk->floatingAssignedUsers($license),
            'seatUsers' => $bulk->seatAssignedUsers($license),
        ]);
    }

    /**
     * Bulk-assign a license to a set of users (see BulkUserAssignment).
     */
    public function bulkAddUsers(Request $request, License $license, BulkUserAssignment $bulk): RedirectResponse
    {
        $this->authorize('floating_licenses.allocate');

        $validated = $request->validate([
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'integer|exists:users,id',
        ]);

        $result = $bulk->addUsers($license, array_map('intval', $validated['user_ids']));

        return redirect()->route('licenses.show', $license)
            ->with($result['failed'] > 0 ? 'warning' : 'success', trans('floating-licenses::floating.message.bulk_add_result', $result));
    }

    /**
     * Bulk-remove a license from a set of users (see BulkUserAssignment).
     */
    public function bulkRemoveUsers(Request $request, License $license, BulkUserAssignment $bulk): RedirectResponse
    {
        $this->authorize('floating_licenses.release');

        $validated = $request->validate([
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'integer|exists:users,id',
        ]);

        $result = $bulk->removeUsers($license, array_map('intval', $validated['user_ids']));

        return redirect()->route('licenses.show', $license)
            ->with($result['failed'] > 0 ? 'warning' : 'success', trans('floating-licenses::floating.message.bulk_remove_result', $result));
    }
}
