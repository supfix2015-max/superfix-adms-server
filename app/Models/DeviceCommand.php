<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeviceCommand extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'device_id',
        'command',
        'parameters',
        'status',
        'response',
        'response_payload',
        'command_payload',
        'retry_count',
        'sent_at',
        'executed_at',
        'acknowledged_at',
        'error_message',
        'created_by',
    ];

    protected $casts = [
        'parameters' => 'array',
        'response_payload' => 'array',
        'sent_at' => 'datetime',
        'executed_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    public const COMMAND_TYPES = [
        'RESTART',
        'CLEAR_LOGS',
        'SET_TIME',
        'UPDATE_TIME',
        'ENABLE_ATTENDANCE',
        'DISABLE_ATTENDANCE',
        'SET_ATTENDANCE_MODE',
        'SYNC_EMPLOYEES',
        'PULL_EMPLOYEES',
        'PULL_ATTENDANCE',
    ];

    public const KNOWN_COMMANDS = self::COMMAND_TYPES;

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class)->withoutGlobalScope(SoftDeletingScope::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->creator();
    }

    public function getCommandTypeAttribute(): ?string
    {
        return $this->command;
    }

    public function scopeByDevice($query, int $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('command', $type);
    }
}
