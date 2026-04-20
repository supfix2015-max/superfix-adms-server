<?php

namespace App\DataTransferObjects;

final readonly class SyncableEmployeeData
{
    public function __construct(
        public int $employeeRecordId,
        public string $employeeId,
        public string $syncUid,
        public string $syncName,
        public ?string $cardNo,
        public ?string $devicePassword,
        public int $devicePrivilege,
        public ?string $groupCode,
        public ?string $timezoneCode,
        public ?string $verificationMode,
        public ?string $viceCardNo,
        public ?string $validFrom,
        public ?string $validUntil,
        public array $biometrics,
        public ?string $department,
        public array $rawPayload,
        public string $payloadHash,
    ) {}

    public function toSnapshot(): array
    {
        return [
            'employee_record_id' => $this->employeeRecordId,
            'employee_id' => $this->employeeId,
            'sync_uid' => $this->syncUid,
            'sync_name' => $this->syncName,
            'card_no' => $this->cardNo,
            'device_password' => $this->devicePassword,
            'device_privilege' => $this->devicePrivilege,
            'group_code' => $this->groupCode,
            'timezone_code' => $this->timezoneCode,
            'verification_mode' => $this->verificationMode,
            'vice_card_no' => $this->viceCardNo,
            'valid_from' => $this->validFrom,
            'valid_until' => $this->validUntil,
            'biometrics' => $this->biometrics,
            'department' => $this->department,
            'payload_hash' => $this->payloadHash,
        ];
    }
}
