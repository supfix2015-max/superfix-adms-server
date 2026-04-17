<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Device extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_OFFLINE = 'offline';

    protected $fillable = [
        'name',
        'serial_number',
        'sn',
        'ip_address',
        'port',
        'status',
        'device_type',
        'firmware_version',
        'hardware_version',
        'capacity',
        'enrolled_faces',
        'enrolled_fingerprints',
        'api_token',
        'last_seen',
        'last_heartbeat',
        'last_sync',
        'raw_config',
        'notes',
    ];

    protected $casts = [
        'raw_config' => 'array',
        'last_seen' => 'datetime',
        'last_sync' => 'datetime',
        'last_heartbeat' => 'datetime',
        'capacity' => 'integer',
        'enrolled_faces' => 'integer',
        'enrolled_fingerprints' => 'integer',
        'port' => 'integer',
    ];

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function rawLogs(): HasMany
    {
        return $this->hasMany(RawLog::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function commands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class);
    }

    public function errorLogs(): HasMany
    {
        return $this->hasMany(ErrorLog::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeOnline(Builder $query): Builder
    {
        $threshold = now()->subMinutes((int) config('zkteco.heartbeat_ttl_minutes', 5));

        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->where(function (Builder $builder) use ($threshold): void {
                $builder->where('last_seen', '>', $threshold)
                    ->orWhere('last_heartbeat', '>', $threshold);
            });
    }

    public function scopeOffline(Builder $query): Builder
    {
        $threshold = now()->subMinutes((int) config('zkteco.heartbeat_ttl_minutes', 5));

        return $query
            ->where(function (Builder $builder) use ($threshold): void {
                $builder->whereNull('last_seen')
                    ->orWhere('last_seen', '<=', $threshold);
            });
    }

    public function getSerialNumberAttribute($value): ?string
    {
        return $value ?: ($this->attributes['sn'] ?? null);
    }

    public function getSnAttribute($value): ?string
    {
        return $value ?: ($this->attributes['serial_number'] ?? null);
    }

    public function getConnectedEmployeesAttribute(): int
    {
        return (int) $this->attendanceLogs()
            ->whereNotNull('employee_id')
            ->distinct('employee_id')
            ->count('employee_id');
    }

    public function isOnline(): bool
    {
        $lastSeen = $this->last_seen ?? $this->last_heartbeat;

        return $lastSeen instanceof Carbon
            && $lastSeen->gt(now()->subMinutes((int) config('zkteco.heartbeat_ttl_minutes', 5)));
    }

    public function getStatusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'badge-success',
            self::STATUS_INACTIVE => 'badge-warning',
            self::STATUS_OFFLINE => 'badge-danger',
            default => 'badge-secondary',
        };
    }

    public function getTodayAttendanceCount(): int
    {
        return $this->attendanceLogs()
            ->whereDate('log_time', today())
            ->count();
    }

    public function recordHeartbeat(): void
    {
        $this->forceFill([
            'last_seen' => now(),
            'last_heartbeat' => now(),
            'status' => self::STATUS_ACTIVE,
        ])->save();
    }
}
