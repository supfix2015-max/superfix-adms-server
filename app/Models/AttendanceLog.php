<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Eloquent\SoftDeletes;

class AttendanceLog extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'device_id',
        'employee_id',
        'employee_code',
        'uid',
        'log_type',
        'punch_state',
        'verify_type',
        'work_code',
        'log_time',
        'device_log_id',
        'payload_hash',
        'raw_payload',
        'temperature',
        'status',
        'source',
        'error_message',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'log_time' => 'datetime',
    ];

    public const LOG_TYPES = ['checkin', 'checkout', 'break', 'overtime', 'unknown'];

    public const VERIFY_TYPES = ['face', 'fingerprint', 'rfid', 'palm', 'password', 'unknown'];

    public const STATUSES = ['pending', 'processed', 'verified', 'error'];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class)->withoutGlobalScope(SoftDeletingScope::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withoutGlobalScope(SoftDeletingScope::class);
    }

    public function scopeByDevice($query, int $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }

    public function scopeByEmployee($query, int $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('log_type', $type);
    }

    public function scopeByDate($query, Carbon $date)
    {
        return $query->whereDate('log_time', $date);
    }

    public function scopeDateRange($query, Carbon $startDate, Carbon $endDate)
    {
        return $query->whereBetween('log_time', [$startDate, $endDate]);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeProcessed($query)
    {
        return $query->where('status', 'processed');
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
