<?php

namespace App\Events;

use App\Models\Device;
use App\Models\Employee;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BiometricTemplateStored
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Device $device,
        public Employee $employee,
        public array $syncKeys = [],
    ) {}
}
