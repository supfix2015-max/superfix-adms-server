<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeBiometric extends Model
{
    use HasFactory;

    public const TYPE_FINGERPRINT = 'fingerprint';

    public const TYPE_FACE = 'face';

    public const TYPE_PHOTO = 'photo';

    protected $fillable = [
        'device_id',
        'employee_id',
        'employee_code',
        'biometric_type',
        'template_index',
        'file_name',
        'is_valid',
        'data_size',
        'template_data',
        'sync_key',
        'raw_payload',
    ];

    protected $casts = [
        'is_valid' => 'boolean',
        'data_size' => 'integer',
        'raw_payload' => 'array',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
