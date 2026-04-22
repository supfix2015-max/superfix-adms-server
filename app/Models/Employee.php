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

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_ON_LEAVE = 'ON_LEAVE';

    public const STATUS_SUSPENDED = 'SUSPENDED';

    public const STATUS_RESIGNED = 'RESIGNED';

    public const STATUS_TERMINATED = 'TERMINATED';

    public const STATUS_RETIRED = 'RETIRED';

    public const STATUS_INACTIVE = 'INACTIVE';

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
        'suspended_at',
        'resigned_at',
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
        'suspended_at' => 'datetime',
        'resigned_at' => 'datetime',
    ];

    protected $appends = ['full_name', 'status_label', 'status_badge_class'];

    public static function statusOptions(): array
    {
        return [
            self::STATUS_ACTIVE,
            self::STATUS_ON_LEAVE,
            self::STATUS_SUSPENDED,
            self::STATUS_RESIGNED,
            self::STATUS_TERMINATED,
            self::STATUS_RETIRED,
            self::STATUS_INACTIVE,
        ];
    }

    public static function acceptedStatusInputs(): array
    {
        return array_values(array_unique(array_merge(
            self::statusOptions(),
            array_map('strtolower', self::statusOptions()),
        )));
    }

    public static function syncEligibleStatuses(): array
    {
        return [
            self::STATUS_ACTIVE,
            self::STATUS_ON_LEAVE,
        ];
    }

    public static function deviceRemovalStatuses(): array
    {
        return [
            self::STATUS_SUSPENDED,
            self::STATUS_RESIGNED,
            self::STATUS_TERMINATED,
            self::STATUS_RETIRED,
        ];
    }

    public static function resignedStatuses(): array
    {
        return [
            self::STATUS_RESIGNED,
            self::STATUS_TERMINATED,
            self::STATUS_RETIRED,
        ];
    }

    public static function normalizeStatus(?string $status): ?string
    {
        $status = strtoupper(trim((string) $status));
        $status = str_replace(' ', '_', $status);

        return in_array($status, self::statusOptions(), true) ? $status : null;
    }

    public static function isSyncEligibleStatus(?string $status): bool
    {
        return in_array(self::normalizeStatus($status), self::syncEligibleStatuses(), true);
    }

    public static function shouldRemoveFromDevices(?string $status): bool
    {
        return in_array(self::normalizeStatus($status), self::deviceRemovalStatuses(), true);
    }

    public static function labelForStatus(?string $status): string
    {
        $status = self::normalizeStatus($status) ?? strtoupper(trim((string) $status));

        return Str::of($status)->replace('_', ' ')->title()->value();
    }

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

    public function setStatusAttribute(?string $value): void
    {
        $this->attributes['status'] = self::normalizeStatus($value) ?? self::STATUS_ACTIVE;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::labelForStatus($this->status);
    }

    public function getStatusBadgeClassAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'success',
            self::STATUS_ON_LEAVE => 'info text-dark',
            self::STATUS_SUSPENDED => 'warning text-dark',
            self::STATUS_TERMINATED => 'danger',
            self::STATUS_RETIRED => 'primary',
            self::STATUS_RESIGNED => 'dark',
            default => 'secondary',
        };
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
        return $query->where('status', self::STATUS_ACTIVE);
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
