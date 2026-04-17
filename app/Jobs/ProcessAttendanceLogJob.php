<?php

namespace App\Jobs;

use App\Models\Device;
use App\Repositories\AttendanceLogRepository;
use App\Repositories\EmployeeRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessAttendanceLogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        protected int $deviceId,
        protected array $logData
    ) {}

    public function handle(): void
    {
        try {
            $device = Device::findOrFail($this->deviceId);
            $attendanceRepo = app(AttendanceLogRepository::class);
            $employeeRepo = app(EmployeeRepository::class);

            // Create the attendance log
            $log = $attendanceRepo->create([
                'device_id' => $this->deviceId,
                'employee_code' => $this->logData['employee_code'],
                'log_type' => $this->logData['log_type'],
                'verify_type' => $this->logData['verify_type'],
                'log_time' => $this->logData['log_time'],
                'device_log_id' => $this->logData['device_log_id'] ?? null,
                'temperature' => $this->logData['temperature'] ?? null,
                'raw_payload' => $this->logData['raw_payload'] ?? null,
                'status' => 'pending',
            ]);

            // Try to match employee code to employee ID
            if ($this->logData['employee_code']) {
                $employee = $employeeRepo->findByEmployeeId($this->logData['employee_code']);
                if ($employee) {
                    $attendanceRepo->update($log->id, [
                        'employee_id' => $employee->id,
                        'status' => 'verified',
                    ]);
                } else {
                    // Log unmatched employee for later review
                    Log::warning('Unmatched employee code from device', [
                        'device_id' => $this->deviceId,
                        'employee_code' => $this->logData['employee_code'],
                        'log_id' => $log->id,
                    ]);
                }
            }

            // Mark as processed
            $attendanceRepo->update($log->id, ['status' => 'processed']);

        } catch (\Exception $e) {
            Log::error('Failed to process attendance log', [
                'device_id' => $this->deviceId,
                'log_data' => $this->logData,
                'error' => $e->getMessage(),
            ]);

            $this->fail($e);
        }
    }
}
