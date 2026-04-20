<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class Employee extends Model
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'device_id',
        'uid',
        'sync_uid',
        'card_no',
        'device_password',
        'device_privilege',
        'group_code',
        'timezone_code',
        'verification_mode',
        'vice_card_no',
        'valid_from',
        'valid_until',
        'name',
        'sync_name',
        'first_name',
        'last_name',
        'email',
        'phone',
        'department',
        'status',
        'sync_enabled',
        'hire_date',
        'notes',
        'raw_payload',
        'sync_validation_errors',
        'last_sync_validated_at',
        'last_synced_payload_hash',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'raw_payload' => 'array',
        'sync_enabled' => 'boolean',
        'sync_validation_errors' => 'array',
        'last_sync_validated_at' => 'datetime',
        'device_privilege' => 'integer',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
    ];

    protected $appends = ['full_name'];

    public function getFullNameAttribute(): string
    {
        if (! empty($this->name)) {
            return $this->name;
        }

        $firstName = $this->attributes['first_name'] ?? '';
        $lastName = $this->attributes['last_name'] ?? '';

        return trim("{$firstName} {$lastName}");
    }

    public function getFirstNameAttribute(): string
    {
        return Str::of($this->full_name)->before(' ')->value();
    }

    public function getLastNameAttribute(): string
    {
        $fullName = trim($this->full_name);
        if (! str_contains($fullName, ' ')) {
            return '';
        }

        return Str::of($fullName)->after(' ')->value();
    }

    public function setFirstNameAttribute(?string $value): void
    {
        $this->attributes['name'] = trim($value.' '.$this->getLastNameAttribute());
    }

    public function setLastNameAttribute(?string $value): void
    {
        $this->attributes['name'] = trim($this->getFirstNameAttribute().' '.$value);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class, 'employee_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function biometrics(): HasMany
    {
        return $this->hasMany(EmployeeBiometric::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeSyncSafe($query)
    {
        return $query
            ->where('sync_enabled', true)
            ->whereNotNull('sync_uid')
            ->whereNotNull('sync_name');
    }

    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($builder) use ($search): void {
            $builder->where('name', 'like', "%{$search}%")
                ->orWhere('employee_id', 'like', "%{$search}%")
                ->orWhere('uid', 'like', "%{$search}%")
                ->orWhere('card_no', 'like', "%{$search}%");
        });
    }

    public function routeNotificationForMail(): ?string
    {
        $email = trim((string) $this->email);

        return $email !== '' ? $email : null;
    }
}
