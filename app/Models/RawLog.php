<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $device_id
 * @property string $endpoint
 * @property string $payload
 * @property array $parsed_data
 * @property string $processing_status
 * @property int $record_count
 * @property string $error_message
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class RawLog extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'device_id',
        'endpoint',
        'payload',
        'parsed_data',
        'processing_status',
        'record_count',
        'error_message',
    ];

    protected $casts = [
        'parsed_data' => 'json',
    ];

    const ENDPOINTS = ['/iclock/cdata', '/iclock/test', '/iclock/getrequest', '/iclock/devicecmd', '/api/device/logs'];

    const STATUSES = ['received', 'processing', 'processed', 'failed'];

    /**
     * Relationships
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class)->withoutGlobalScope(SoftDeletingScope::class);
    }

    /**
     * Scopes
     */
    public function scopeByDevice($query, $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }

    public function scopeByEndpoint($query, $endpoint)
    {
        return $query->where('endpoint', $endpoint);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('processing_status', $status);
    }

    public function scopeRecent($query, $days = 1)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
