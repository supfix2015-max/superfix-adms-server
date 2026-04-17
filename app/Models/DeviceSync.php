<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $device_id
 * @property Carbon $sync_started_at
 * @property Carbon $sync_completed_at
 * @property string $status
 * @property int $total_records
 * @property int $processed_records
 * @property string $error_message
 * @property array $sync_metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class DeviceSync extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'device_id',
        'sync_started_at',
        'sync_completed_at',
        'status',
        'total_records',
        'processed_records',
        'error_message',
        'sync_metadata',
    ];

    protected $casts = [
        'sync_started_at' => 'datetime',
        'sync_completed_at' => 'datetime',
        'sync_metadata' => 'json',
    ];

    const STATUSES = ['pending', 'syncing', 'completed', 'failed'];

    /**
     * Relationships
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Scopes
     */
    public function scopeByDevice($query, $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeRecent($query, $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
