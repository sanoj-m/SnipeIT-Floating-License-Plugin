<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Floating Licenses Defaults
    |--------------------------------------------------------------------------
    |
    | These values are used as defaults when a new floating license pool
    | configuration is created. Each pool can override them individually.
    |
    */

    // How long an allocation lease lasts before it may be expired (minutes).
    // Null means allocations never expire — the default for new pools.
    'lease_duration_minutes' => null,

    // An allocation with no heartbeat for this long is considered idle (minutes).
    // Null means no idle reclamation — the default for new pools.
    'idle_timeout_minutes' => null,

    // 'pool_slot' splits total_cost evenly across pool slots.
    // 'active_user' splits total_cost evenly across currently active allocations.
    'cost_mode' => 'pool_slot',

    // Whether pools may exceed their pool_size by default.
    'allow_over_allocation' => false,

    // Writing an Actionlog entry for every heartbeat gets noisy; off by default.
    'log_heartbeats' => false,
];
