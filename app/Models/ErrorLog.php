<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Eloquent\SoftDeletes;

class ErrorLog extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'device_id',
        'type',
        'error_code',
        'message',
        'severity',
        'resolved',
    ];

    protected $casts = [
        'resolved' => 'boolean',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class)->withoutGlobalScope(SoftDeletingScope::class);
    }

    public function getIsResolvedAttribute(): bool
    {
        return (bool) $this->resolved;
    }

    public function scopeByDevice($query, int $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }

    public function scopeUnresolved($query)
    {
        return $query->where('resolved', false);
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
