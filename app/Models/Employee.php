<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'device_id',
        'uid',
        'card_no',
        'name',
        'first_name',
        'last_name',
        'email',
        'phone',
        'department',
        'status',
        'hire_date',
        'notes',
        'raw_payload',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'raw_payload' => 'array',
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

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
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
}
