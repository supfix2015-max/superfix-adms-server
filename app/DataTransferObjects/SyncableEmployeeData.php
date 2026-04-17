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
            'department' => $this->department,
            'payload_hash' => $this->payloadHash,
        ];
    }
}
