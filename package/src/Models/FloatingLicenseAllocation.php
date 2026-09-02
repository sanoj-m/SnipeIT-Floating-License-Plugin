<?php

namespace SnipeIt\FloatingLicenses\Models;

use App\Models\Asset;
use App\Models\License;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FloatingLicenseAllocation extends Model
{
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RELEASED = 'released';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    protected $table = 'floating_license_allocations';

    protected $fillable = [
        'license_id',
        'user_id',
        'asset_id',
        'status',
        'allocated_cost',
        'allocated_at',
        'last_seen_at',
        'expires_at',
        'released_at',
        'notes',
    ];

    protected $casts = [
        'license_id' => 'integer',
        'user_id' => 'integer',
        'asset_id' => 'integer',
        'allocated_cost' => 'decimal:2',
        'allocated_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'expires_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    /**
     * Scope a query to only active allocations.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class, 'license_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    /**
     * Whether this allocation is currently holding a pool slot.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
