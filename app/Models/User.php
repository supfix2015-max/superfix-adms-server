<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon $email_verified_at
 * @property string $password
 * @property string $role
 * @property bool $is_active
 * @property bool $force_to_change_password
 * @property Carbon $last_login_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'force_to_change_password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
        'force_to_change_password' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    const ROLES = ['admin', 'operator', 'viewer'];

    /**
     * Relationships
     */
    public function deviceCommands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class, 'created_by');
    }

    /**
     * Helper methods
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isOperator(): bool
    {
        return $this->role === 'operator';
    }

    public function isViewer(): bool
    {
        return $this->role === 'viewer';
    }

    public function canManageDevices(): bool
    {
        return in_array($this->role, ['admin', 'operator']);
    }

    public function canViewReports(): bool
    {
        return true; // All active users can view reports
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByRole($query, $role)
    {
        return $query->where('role', $role);
    }

    public function scopeAdmins($query)
    {
        return $query->where('role', 'admin');
    }

    public function scopeOperators($query)
    {
        return $query->where('role', 'operator');
    }
}
