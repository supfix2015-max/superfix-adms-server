<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\RawLog;
use App\Services\ZKTecoPayloadParser;
use App\Services\ZKTecoService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Throwable;

class ReprocessBiometricCallbacksCommand extends Command
{
    protected $signature = 'adms:reprocess-biometric-callbacks
        {--employee= : Replay only callbacks for one employee code}
        {--device= : Replay only callbacks for one device serial number or local device id}
        {--limit=200 : Maximum zero-record /iclock/cdata raw logs to scan}
        {--dry-run : Show recoverable biometric callbacks without replaying them}';

    protected $description = 'Replay zero-record ADMS /iclock/cdata callbacks that should persist biometric templates.';

    public function handle(ZKTecoPayloadParser $payloadParser, ZKTecoService $zkTecoService): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $employeeFilter = trim((string) $this->option('employee'));
        $deviceFilter = trim((string) $this->option('device'));
        $dryRun = (bool) $this->option('dry-run');

        $candidates = $this->findCandidates($payloadParser, $limit, $employeeFilter, $deviceFilter);

        if ($candidates === []) {
            $this->info('No recoverable biometric callbacks found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Raw Log', 'Device', 'Employee', 'Types', 'Records', 'Created'],
            array_map(
                static fn (array $candidate): array => [
                    $candidate['raw_log']->id,
                    $candidate['device']->serial_number ?: $candidate['device']->sn ?: 'unknown',
                    implode(', ', $candidate['employee_codes']),
                    implode(', ', $candidate['types']),
                    count($candidate['parsed']['records']),
                    $candidate['raw_log']->created_at?->toDateTimeString() ?? '-',
                ],
                $candidates,
            ),
        );

        if ($dryRun) {
            $this->info(sprintf('Dry run complete. %d callback(s) are recoverable.', count($candidates)));

            return self::SUCCESS;
        }

        $replayed = 0;
        $failed = 0;

        foreach ($candidates as $candidate) {
            /** @var RawLog $rawLog */
            $rawLog = $candidate['raw_log'];
            /** @var Device $device */
            $device = $candidate['device'];

            try {
                $response = $zkTecoService->handleCdata($this->buildReplayRequest($rawLog, $device));
                $this->line(sprintf(
                    'Replayed raw log %d for device %s -> %s',
                    $rawLog->id,
                    $device->serial_number ?: $device->sn ?: 'unknown',
                    trim($response),
                ));
                $replayed++;
            } catch (Throwable $exception) {
                $this->warn(sprintf(
                    'Replay failed for raw log %d: %s',
                    $rawLog->id,
                    $exception->getMessage(),
                ));
                $failed++;
            }
        }

        $this->info(sprintf('Replay complete. Replayed: %d, failed: %d.', $replayed, $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function findCandidates(
        ZKTecoPayloadParser $payloadParser,
        int $limit,
        string $employeeFilter,
        string $deviceFilter,
    ): array {
        $query = RawLog::query()
            ->with('device')
            ->where('endpoint', '/iclock/cdata')
            ->where('record_count', 0)
            ->whereIn('processing_status', ['processed', 'failed'])
            ->orderBy('id');

        if ($deviceFilter !== '') {
            $query->whereHas('device', function ($builder) use ($deviceFilter): void {
                $builder->where('serial_number', $deviceFilter)
                    ->orWhere('sn', $deviceFilter);

                if (ctype_digit($deviceFilter)) {
                    $builder->orWhere('id', (int) $deviceFilter);
                }
            });
        }

        $candidates = [];

        foreach ($query->limit($limit)->get() as $rawLog) {
            $device = $rawLog->device;
            if (! $device) {
                continue;
            }

            $parsed = $payloadParser->parseBiometricPayload((string) $rawLog->payload);
            if (($parsed['records'] ?? []) === []) {
                continue;
            }

            $employeeCodes = collect($parsed['records'])
                ->pluck('employee_code')
                ->filter()
                ->map(static fn (mixed $code): string => trim((string) $code))
                ->unique()
                ->values()
                ->all();

            if ($employeeFilter !== '' && ! in_array($employeeFilter, $employeeCodes, true)) {
                continue;
            }

            $types = collect($parsed['records'])
                ->pluck('biometric_type')
                ->filter()
                ->map(static fn (mixed $type): string => trim((string) $type))
                ->unique()
                ->values()
                ->all();

            $candidates[] = [
                'raw_log' => $rawLog,
                'device' => $device,
                'parsed' => $parsed,
                'employee_codes' => $employeeCodes,
                'types' => $types,
            ];
        }

        return $candidates;
    }

    private function buildReplayRequest(RawLog $rawLog, Device $device): Request
    {
        $table = strtoupper(trim((string) ($rawLog->parsed_data['table'] ?? 'OPERLOG')));

        return Request::create(
            '/iclock/cdata',
            'POST',
            [
                'SN' => $device->serial_number ?: $device->sn,
                'table' => $table !== '' ? $table : 'OPERLOG',
                'Stamp' => $rawLog->id,
            ],
            [],
            [],
            [
                'REMOTE_ADDR' => $device->ip_address ?: '127.0.0.1',
                'CONTENT_TYPE' => 'text/plain',
            ],
            (string) $rawLog->payload,
        );
    }
}
