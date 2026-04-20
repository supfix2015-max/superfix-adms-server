<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class BiometricTemplate extends Model
{
    use HasFactory;

    public const TYPE_FINGERPRINT = 'fingerprint';

    public const TYPE_FACE = 'face';

    protected $fillable = [
        'employee_id',
        'device_id',
        'finger_index',
        'template_type',
        'template_index',
        'data_size',
        'template_hash',
        'template_data',
        'metadata',
    ];

    protected $casts = [
        'finger_index' => 'integer',
        'data_size' => 'integer',
        'metadata' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class)->withoutGlobalScopes();
    }

    protected function templateData(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): ?string {
                if ($value === null) {
                    return null;
                }

                $payload = is_resource($value) ? stream_get_contents($value) : (string) $value;
                if ($payload === '') {
                    return null;
                }

                try {
                    return Crypt::decryptString($payload);
                } catch (\Throwable) {
                    return $payload;
                }
            },
            set: function (mixed $value): ?string {
                if ($value === null || $value === '') {
                    return null;
                }

                return Crypt::encryptString((string) $value);
            },
        );
    }
}
