<?php

namespace App\DataTransferObjects;

use Carbon\CarbonInterface;

final readonly class AttendanceRecordData
{
    public function __construct(
        public string $employeeCode,
        public ?string $uid,
        public ?string $name,
        public ?string $cardNo,
        public string $logType,
        public string $punchState,
        public string $verifyType,
        public ?string $workCode,
        public CarbonInterface $logTime,
        public ?string $deviceLogId,
        public ?string $temperature,
        public array $rawPayload,
        public string $payloadHash,
    ) {}
}
