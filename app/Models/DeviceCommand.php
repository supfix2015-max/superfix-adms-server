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
        'device_sync_id',
        'employee_id',
        'command',
        'parameters',
        'status',
        'response',
        'response_payload',
        'command_payload',
        'fingerprint',
        'retry_count',
        'sent_at',
        'executed_at',
        'acknowledged_at',
        'expires_at',
        'failed_at',
        'error_message',
        'created_by',
    ];

    protected $casts = [
        'parameters' => 'array',
        'response_payload' => 'array',
        'sent_at' => 'datetime',
        'executed_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'expires_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public const COMMAND_TYPES = [
        'RESTART',
        'CLEAR_LOGS',
        'SET_TIME',
        'UPDATE_TIME',
        'SET_TIMEZONE',
        'ENABLE_ATTENDANCE',
        'DISABLE_ATTENDANCE',
        'SET_ATTENDANCE_MODE',
        'SYNC_EMPLOYEES',
        'SYNC_BIOMETRICS',
        'ENROLL_FINGERPRINT',
        'ENROLL_FACE',
        'PULL_EMPLOYEES',
        'PULL_ATTENDANCE',
    ];

    public const KNOWN_COMMANDS = [...self::COMMAND_TYPES, 'RM_ADMIN_PRIVILEGE'];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class)->withoutGlobalScope(SoftDeletingScope::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deviceSync(): BelongsTo
    {
        return $this->belongsTo(DeviceSync::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
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
