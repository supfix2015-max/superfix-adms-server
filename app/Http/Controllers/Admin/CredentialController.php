<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Employee;
use App\Services\BiometricEnrollmentProgressService;
use App\Services\CommandService;
use App\Services\UserSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CredentialController extends Controller
{
    private const SUPPORTED_TYPES = ['CARD', 'PASSCODE', 'COMMON'];

    public function __construct(
        protected UserSyncService $userSyncService,
        protected CommandService $commandService,
        protected BiometricEnrollmentProgressService $biometricEnrollmentProgressService,
    ) {}

    public function index()
    {
        $employees = Employee::query()
            ->orderBy('name')
            ->orderBy('employee_id')
            ->get();

        $credentials = $employees
            ->flatMap(fn (Employee $employee): array => $this->credentialRowsFor($employee))
            ->values();

        $noCredentialEmployees = $employees
            ->filter(fn (Employee $employee): bool => ! $this->employeeHasCredentials($employee))
            ->values();

        $onlineDevices = Device::query()
            ->online()
            ->orderBy('name')
            ->get();

        return view('admin.credentials.index', [
            'credentials' => $credentials,
            'employees' => $employees,
            'noCredentialEmployees' => $noCredentialEmployees,
            'onlineDevices' => $onlineDevices,
            'error' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'type' => ['required', Rule::in(self::SUPPORTED_TYPES)],
            'credential_value' => ['required', 'string', 'max:64'],
            'effective_date' => ['nullable', 'date'],
            'expiration_date' => ['nullable', 'date', 'after_or_equal:effective_date'],
        ]);

        $employee = Employee::query()->findOrFail($validated['employee_id']);
        $value = $this->sanitizeCredentialValue($validated['type'], $validated['credential_value']);

        if ($value === null) {
            return back()
                ->withInput()
                ->withErrors(['credential_value' => 'The credential value is invalid for the selected type.']);
        }

        $this->assignCredentialValue($employee, $validated['type'], $value);
        $employee->valid_from = $this->normalizeDeviceDate($validated['effective_date'] ?? null);
        $employee->valid_until = $this->normalizeDeviceDate($validated['expiration_date'] ?? null, true);
        $employee->save();

        $this->refreshSyncSafety($employee);

        return redirect()
            ->route('admin.credentials.index')
            ->with('success', 'Credential saved successfully.');
    }

    public function destroy(Employee $employee, string $type): RedirectResponse
    {
        $type = strtoupper($type);

        if (! in_array($type, self::SUPPORTED_TYPES, true)) {
            abort(404);
        }

        $this->clearCredentialValue($employee, $type);

        if (! $this->employeeHasCredentials($employee)) {
            $employee->valid_from = null;
            $employee->valid_until = null;
        }

        $employee->save();

        $this->refreshSyncSafety($employee);

        return redirect()
            ->route('admin.credentials.index')
            ->with('success', 'Credential removed successfully.');
    }

    public function noCredential(): RedirectResponse
    {
        return redirect()->to(route('admin.credentials.index').'#no-credential');
    }

    public function enrollBiometric(Request $request)
    {
        $validated = $request->validate([
            'enrollment_employee_id' => ['required', 'integer', 'exists:employees,id'],
            'enrollment_device_id' => ['required', 'exists:devices,id'],
            'biometric_type' => ['required', 'in:fingerprint,face'],
            'finger_index' => ['nullable', 'integer', 'min:0', 'max:9'],
            'retry' => ['nullable', 'integer', 'min:1', 'max:15'],
            'overwrite' => ['nullable', 'boolean'],
        ]);

        $employee = Employee::query()->findOrFail($validated['enrollment_employee_id']);
        $device = Device::query()->findOrFail($validated['enrollment_device_id']);

        if (! $device->isOnline()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => "Device {$device->name} is not online. Remote biometric enrollment requires an actively polling device.",
                ], 422);
            }

            return redirect()
                ->to(route('admin.credentials.index').'#biometric-enrollment')
                ->withInput()
                ->withErrors([
                    'enrollment' => "Device {$device->name} is not online. Remote biometric enrollment requires an actively polling device.",
                ]);
        }

        $pin = $this->resolveEnrollmentPin($employee);
        if ($pin === null) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Employee must have a numeric device PIN/UID before remote biometric enrollment can be queued.',
                ], 422);
            }

            return redirect()
                ->to(route('admin.credentials.index').'#biometric-enrollment')
                ->withInput()
                ->withErrors([
                    'enrollment' => 'Employee must have a numeric device PIN/UID before remote biometric enrollment can be queued.',
                ]);
        }

        $biometricType = $validated['biometric_type'];
        $retry = (int) ($validated['retry'] ?? 3);
        $overwrite = $request->boolean('overwrite', true);

        [$commandName, $parameters] = $this->buildEnrollmentCommandPayload(
            $employee,
            $biometricType,
            $pin,
            $retry,
            $overwrite,
            $validated['finger_index'] ?? null,
        );

        $command = $this->commandService->queueCommand(
            $device->id,
            $commandName,
            $parameters,
            sprintf(
                'Remote %s enrollment for %s (%s) from credentials page',
                $biometricType,
                $employee->full_name,
                $employee->employee_id
            ),
            [
                'employee_id' => $employee->id,
                'force' => true,
            ]
        );

        if ($request->expectsJson()) {
            return response()->json([
                'message' => sprintf(
                    '%s enrollment queued for %s on %s.',
                    ucfirst($biometricType),
                    $employee->full_name,
                    $device->name,
                ),
                'command_id' => $command->id,
                'status_url' => route('admin.employees.biometric-enrollment-status', [
                    'employee' => $employee,
                    'command' => $command,
                ]),
                'status' => $this->biometricEnrollmentProgressService->forCommand($employee, $command),
            ], 202);
        }

        return redirect()
            ->to(route('admin.credentials.index').'#biometric-enrollment')
            ->with(
                'success',
                sprintf(
                    '%s enrollment queued for %s on %s as command #%d. The device must stay online and complete the capture before the template is uploaded back to ADMS.',
                    ucfirst($biometricType),
                    $employee->full_name,
                    $device->name,
                    $command->id
                )
            );
    }

    private function credentialRowsFor(Employee $employee): array
    {
        $rows = [];
        $cardNumber = $this->sanitizeCardNumber((string) $employee->card_no);
        $devicePassword = $this->sanitizeDevicePassword((string) $employee->device_password);
        $commonCode = $this->sanitizeDeviceCode((string) $employee->vice_card_no, 64);

        if ($cardNumber !== null) {
            $rows[] = $this->makeCredentialRow($employee, 'CARD', $cardNumber, $cardNumber);
        }

        if ($devicePassword !== null) {
            $rows[] = $this->makeCredentialRow(
                $employee,
                'PASSCODE',
                Str::mask($devicePassword, '*', 0),
                $devicePassword
            );
        }

        if ($commonCode !== null) {
            $rows[] = $this->makeCredentialRow($employee, 'COMMON', $commonCode, $commonCode);
        }

        return $rows;
    }

    private function makeCredentialRow(Employee $employee, string $type, string $displayValue, string $rawValue): array
    {
        $status = $employee->status_label;
        $statusClass = $employee->status_badge_class;

        if ($employee->valid_until instanceof Carbon && $employee->valid_until->isPast()) {
            $status = 'EXPIRED';
            $statusClass = 'warning text-dark';
        }

        return [
            'employee' => $employee,
            'type' => $type,
            'display_value' => $displayValue,
            'raw_value' => $rawValue,
            'effective_date' => $employee->valid_from?->format('Y-m-d'),
            'expiration_date' => $employee->valid_until?->format('Y-m-d'),
            'status' => $status,
            'status_class' => $statusClass,
        ];
    }

    private function employeeHasCredentials(Employee $employee): bool
    {
        return $this->sanitizeCardNumber((string) $employee->card_no) !== null
            || $this->sanitizeDevicePassword((string) $employee->device_password) !== null
            || $this->sanitizeDeviceCode((string) $employee->vice_card_no, 64) !== null;
    }

    private function assignCredentialValue(Employee $employee, string $type, string $value): void
    {
        match ($type) {
            'CARD' => $employee->card_no = $value,
            'PASSCODE' => $employee->device_password = $value,
            'COMMON' => $employee->vice_card_no = $value,
        };
    }

    private function clearCredentialValue(Employee $employee, string $type): void
    {
        match ($type) {
            'CARD' => $employee->card_no = null,
            'PASSCODE' => $employee->device_password = null,
            'COMMON' => $employee->vice_card_no = null,
        };
    }

    private function sanitizeCredentialValue(string $type, string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return match ($type) {
            'CARD' => $this->sanitizeCardNumber($value),
            'PASSCODE' => $this->sanitizeDevicePassword($value),
            'COMMON' => $this->sanitizeDeviceCode($value, 64),
        };
    }

    private function sanitizeCardNumber(string $value): ?string
    {
        $value = preg_replace('/[^A-Za-z0-9_-]/', '', $value);

        return $value !== '' ? substr($value, 0, 32) : null;
    }

    private function sanitizeDevicePassword(string $value): ?string
    {
        $value = trim($value);

        if ($value === '' || $value === '0') {
            return null;
        }

        $value = Str::ascii($value);
        $value = preg_replace('/[^A-Za-z0-9._@#%*!$:-]/', '', $value);

        return $value !== '' ? substr($value, 0, 64) : null;
    }

    private function sanitizeDeviceCode(string $value, int $maxLength = 32): ?string
    {
        $value = trim($value);

        if ($value === '' || $value === '0') {
            return null;
        }

        $value = Str::ascii($value);
        $value = preg_replace('/[^A-Za-z0-9._:-]/', '', $value);

        return $value !== '' ? substr($value, 0, $maxLength) : null;
    }

    private function normalizeDeviceDate(?string $value, bool $endOfDay = false): ?string
    {
        if (! filled($value)) {
            return null;
        }

        $date = Carbon::parse($value);

        return ($endOfDay ? $date->endOfDay() : $date->startOfDay())->format('Y-m-d H:i:s');
    }

    private function refreshSyncSafety(Employee $employee): void
    {
        $this->userSyncService->refreshDeviceSafeDataset(new Collection([$employee->fresh()]));
    }

    private function buildEnrollmentCommandPayload(
        Employee $employee,
        string $biometricType,
        string $pin,
        int $retry,
        bool $overwrite,
        mixed $fingerIndex,
    ): array {
        return match ($biometricType) {
            'fingerprint' => [
                'ENROLL_FINGERPRINT',
                [
                    'pin' => $pin,
                    'fid' => (string) ($fingerIndex ?? 0),
                    'retry' => $retry,
                    'overwrite' => $overwrite,
                    'biometric_type' => 'fingerprint',
                    'template_index' => (string) ($fingerIndex ?? 0),
                ],
            ],
            'face' => [
                'ENROLL_FACE',
                array_filter([
                    'pin' => $pin,
                    'card_no' => $employee->card_no,
                    'retry' => $retry,
                    'overwrite' => $overwrite,
                    'biometric_type' => 'face',
                    'template_index' => '50',
                ], static fn ($value) => $value !== null && $value !== ''),
            ],
        };
    }

    private function resolveEnrollmentPin(Employee $employee): ?string
    {
        $candidates = [
            $employee->sync_uid,
            $employee->uid,
            $employee->employee_id,
        ];

        foreach ($candidates as $candidate) {
            $candidate = preg_replace('/[^0-9]/', '', trim((string) $candidate));

            if ($candidate !== '') {
                return substr($candidate, 0, 32);
            }
        }

        return null;
    }
}
