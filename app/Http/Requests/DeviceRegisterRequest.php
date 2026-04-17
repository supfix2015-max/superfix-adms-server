<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeviceRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'sn' => 'required|string|unique:devices',
            'ip_address' => 'required|ip',
            'port' => 'integer|between:1,65535',
            'device_type' => 'in:face,fingerprint,rfid,mixed',
            'firmware_version' => 'nullable|string',
            'hardware_version' => 'nullable|string',
            'capacity' => 'nullable|integer|min:1',
        ];
    }
}
