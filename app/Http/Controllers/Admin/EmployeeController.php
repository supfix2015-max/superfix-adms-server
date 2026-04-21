<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Repositories\EmployeeRepository;
use App\Services\BiometricEnrollmentProgressService;
use App\Services\BiometricSyncService;
use App\Services\CommandService;
use App\Services\EmployeeService;
use App\Services\UserSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class EmployeeController extends Controller
{
    public function __construct(
        protected EmployeeService $employeeService,
        protected EmployeeRepository $employeeRepository,
        protected UserSyncService $userSyncService,
        protected CommandService $commandService,
        protected BiometricSyncService $biometricSyncService,
        protected BiometricEnrollmentProgressService $biometricEnrollmentProgressService,
    ) {}

    public function index(Request $request)
    {
        $filters = [
            'search' => $request->input('search'),
            'status' => $request->input('status'),
            'department' => $request->input('department'),
        ];

        $employees = $this->employeeRepository->paginateWithFilters($filters, 20);
        $departments = $this->employeeRepository->getDepartments();

        return view('admin.employees.index', [
            'employees' => $employees,
            'filters' => $filters,
            'departments' => $departments,
        ]);
    }

    public function create()
    {
        $departments = $this->employeeRepository->getDepartments();

        return view('admin.employees.create', [
            'departments' => $departments,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|string|unique:employees',
            'uid' => 'nullable|digits_between:1,32',
            'card_no' => 'nullable|string|max:32',
            'device_password' => 'nullable|string|max:64',
            'device_privilege' => 'nullable|integer|min:0|max:14',
            'group_code' => 'nullable|string|max:32',
            'timezone_code' => 'nullable|string|max:32',
            'verification_mode' => 'nullable|string|max:32',
            'vice_card_no' => 'nullable|string|max:64',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
            'status' => 'nullable|in:active,inactive,suspended',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:employees',
            'phone' => 'nullable|string',
            'department' => 'nullable|string',
            'hire_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        try {
            $employee = $this->employeeService->createEmployee($this->prepareEmployeePayload($validated));
            $this->refreshSyncSafety($employee);

            return redirect()->route('admin.employees.show', $employee)
                ->with('success', 'Employee created successfully');
        } catch (\Exception $e) {
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function show(Employee $employee)
    {
        $employee->load([
            'attendances',
            'biometrics' => function ($query) {
                $query->with('device')->latest()->limit(10);
            },
        ]);

        $onlineDevices = Device::query()
            ->online()
            ->orderBy('name')
            ->get();

        $recentBiometricPushCommands = DeviceCommand::query()
            ->with('device')
            ->where('employee_id', $employee->id)
            ->where('command', 'SYNC_BIOMETRICS')
            ->latest('id')
            ->limit(15)
            ->get();

        return view('admin.employees.show', [
            'employee' => $employee,
            'onlineDevices' => $onlineDevices,
            'enrollmentPin' => $this->resolveEnrollmentPin($employee),
            'recentBiometricPushCommands' => $recentBiometricPushCommands,
        ]);
    }

    public function enrollBiometric(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'device_id' => 'required|exists:devices,id',
            'biometric_type' => 'required|in:fingerprint,face',
            'finger_index' => 'nullable|integer|min:0|max:9',
            'retry' => 'nullable|integer|min:1|max:15',
            'overwrite' => 'nullable|boolean',
        ]);

        $device = Device::query()->findOrFail($validated['device_id']);

        if (! $device->isOnline()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => "Device {$device->name} is not online. Remote biometric enrollment requires an actively polling device.",
                ], 422);
            }

            return back()->withErrors([
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

            return back()->withErrors([
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
                'Remote %s enrollment for %s (%s)',
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
            ->route('admin.employees.show', $employee)
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

    public function biometricEnrollmentStatus(Employee $employee, DeviceCommand $command)
    {
        abort_unless($command->employee_id === $employee->id, 404);
        abort_unless(in_array($command->command, ['ENROLL_FINGERPRINT', 'ENROLL_FACE'], true), 404);

        return response()->json(
            $this->biometricEnrollmentProgressService->forCommand($employee, $command)
        );
    }

    public function pushBiometrics(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'template_type' => 'required|in:all,fingerprint,face',
            'overwrite' => 'nullable|boolean',
            'online_only' => 'nullable|boolean',
        ]);

        try {
            $summary = $this->biometricSyncService->queueAdmsReplay($employee, [
                'template_types' => [$validated['template_type']],
                'overwrite' => $request->boolean('overwrite', true),
                'online_only' => $request->boolean('online_only', true),
            ]);
        } catch (\Throwable $exception) {
            return back()->withErrors([
                'push_biometrics' => $exception->getMessage(),
            ]);
        }

        if (($summary['queued_commands'] ?? 0) === 0) {
            $warningText = $summary['warnings'] !== []
                ? ' '.implode(' ', $summary['warnings'])
                : '';

            return back()->withErrors([
                'push_biometrics' => 'No compatible target devices were available for the selected biometric replay.'.$warningText,
            ]);
        }

        $warningText = $summary['warnings'] !== []
            ? ' Warnings: '.implode(' ', $summary['warnings'])
            : '';

        return redirect()
            ->route('admin.employees.show', $employee)
            ->with(
                'success',
                sprintf(
                    'Queued %d ADMS biometric replay command(s) for %d target device(s).%s',
                    $summary['queued_commands'],
                    $summary['queued_targets'],
                    $warningText,
                )
            );
    }

    public function edit(Employee $employee)
    {
        $departments = $this->employeeRepository->getDepartments();

        return view('admin.employees.edit', [
            'employee' => $employee,
            'departments' => $departments,
        ]);
    }

    public function update(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'uid' => 'nullable|digits_between:1,32',
            'card_no' => 'nullable|string|max:32',
            'device_password' => 'nullable|string|max:64',
            'device_privilege' => 'nullable|integer|min:0|max:14',
            'group_code' => 'nullable|string|max:32',
            'timezone_code' => 'nullable|string|max:32',
            'verification_mode' => 'nullable|string|max:32',
            'vice_card_no' => 'nullable|string|max:64',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'department' => 'nullable|string',
            'status' => 'in:active,inactive,suspended',
            'hire_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $this->employeeService->updateEmployee($employee->id, $this->prepareEmployeePayload($validated));
        $employee->refresh();
        $this->refreshSyncSafety($employee);

        return redirect()->route('admin.employees.show', $employee)
            ->with('success', 'Employee updated successfully');
    }

    public function destroy(Employee $employee)
    {
        $summary = $this->employeeService->deleteEmployee($employee->id);

        $message = sprintf(
            'Employee deleted from the database. Queued %d cleanup command(s) for %d device(s).',
            $summary['queued_device_commands'],
            $summary['device_targets'],
        );

        if ($summary['deleted_pending_commands'] > 0) {
            $message .= sprintf(' Removed %d pending employee command(s).', $summary['deleted_pending_commands']);
        }

        return redirect()->route('admin.employees.index')
            ->with('success', $message);
    }

    private function prepareEmployeePayload(array $validated): array
    {
        $validated['uid'] = isset($validated['uid'])
            ? preg_replace('/[^0-9]/', '', (string) $validated['uid'])
            : null;
        $validated['sync_uid'] = $validated['uid'] !== '' ? $validated['uid'] : null;
        $validated['card_no'] = $this->normalizeOptionalString($validated['card_no'] ?? null);
        $validated['device_password'] = $this->normalizeOptionalString($validated['device_password'] ?? null);
        $validated['group_code'] = $this->normalizeOptionalString($validated['group_code'] ?? null);
        $validated['timezone_code'] = $this->normalizeOptionalString($validated['timezone_code'] ?? null);
        $validated['verification_mode'] = $this->normalizeOptionalString($validated['verification_mode'] ?? null);
        $validated['vice_card_no'] = $this->normalizeOptionalString($validated['vice_card_no'] ?? null);
        $validated['status'] = $validated['status'] ?? 'active';

        return $validated;
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

    private function normalizeOptionalString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
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
