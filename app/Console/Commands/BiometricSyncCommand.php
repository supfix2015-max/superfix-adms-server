<?php

namespace App\Console\Commands;

use App\Services\ZKTecoBiometricSyncService;
use Illuminate\Console\Command;
use Throwable;

class BiometricSyncCommand extends Command
{
    protected $signature = 'biometric:sync
        {user_id : Employee code, sync UID, or local employee record id}
        {--source-device= : Registered source device IP address}
        {--target-devices=* : One or more registered target device IP addresses}
        {--overwrite : Overwrite existing templates on target devices}
        {--prefer-udp : Try jmrashed/zkteco or another compatible UDP SDK first}
        {--fallback=notify : Fallback strategy when direct transfer is unsafe: notify, remote-enroll, or log}
        {--json : Output the final summary as JSON}';

    protected $description = 'Sync one employee\'s fingerprint and face templates from a source ZKTeco device to multiple target devices.';

    public function handle(ZKTecoBiometricSyncService $syncService): int
    {
        $userId = trim((string) $this->argument('user_id'));
        $sourceDevice = trim((string) $this->option('source-device'));
        $targetDevices = collect((array) $this->option('target-devices'))
            ->flatMap(static fn (string $value): array => preg_split('/\s*,\s*/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(static fn (string $ip): string => trim($ip))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($sourceDevice === '') {
            $this->error('The --source-device option is required.');

            return self::INVALID;
        }

        if ($targetDevices === []) {
            $this->error('At least one --target-devices value is required.');

            return self::INVALID;
        }

        $fallback = strtolower(trim((string) $this->option('fallback')));
        if (! in_array($fallback, ['notify', 'remote-enroll', 'log'], true)) {
            $this->error("Unsupported fallback [{$fallback}]. Use notify, remote-enroll, or log.");

            return self::INVALID;
        }

        $json = (bool) $this->option('json');
        $options = [
            'overwrite' => (bool) $this->option('overwrite'),
            'prefer_udp' => (bool) $this->option('prefer-udp'),
            'fallback' => $fallback,
        ];

        if (! $json) {
            $this->renderIntro($userId, $sourceDevice, $targetDevices, $options);
        }

        try {
            $summary = $syncService->syncForUser($userId, $sourceDevice, $targetDevices, $options);
        } catch (Throwable $exception) {
            $this->newLine();
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($json) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->renderSummary($summary);

        return self::SUCCESS;
    }

    private function renderIntro(string $userId, string $sourceDevice, array $targetDevices, array $options): void
    {
        $this->info('Biometric User Sync');
        $this->line('Laravel: '.app()->version());
        $this->line("User: {$userId}");
        $this->line("Source device: {$sourceDevice}");
        $this->line('Target devices: '.implode(', ', $targetDevices));
        $this->line('Overwrite: '.($options['overwrite'] ? 'yes' : 'no'));
        $this->line('Prefer UDP: '.($options['prefer_udp'] ? 'yes' : 'no'));
        $this->line('Fallback: '.$options['fallback']);

        $this->newLine();
        $this->warn('UDP Reference');
        $this->line('Legacy docs often spell these as CMD_USER_TEMP_RRQ / CMD_USER_TEMP_WRQ; SDK headers usually use CMD_USERTEMP_RRQ / CMD_USERTEMP_WRQ.');
        $this->line('CMD_USERTEMP_RRQ = 0x0009');
        $this->line('CMD_USERTEMP_WRQ = 0x000A');
        $this->line("Sequence: connect -> disableDevice -> getUserTemplate(\$uid, \$fingerIndex) -> setUserTemplate(\$payload) -> enableDevice -> disconnect");
        $this->line("Packet header: pack('vvvv', \$command, \$checksum, \$sessionId, \$replyId)");
        $this->line("Read payload: pack('vv', \$uid, \$fingerIndex)");
        $this->line("Write payload: pack('vvCC', strlen(\$templateBytes), \$uid, \$fingerIndex, \$valid ? 1 : 0) . \$templateBytes");
        $this->line('Face note: SpeedFace-class terminals may expose face APIs, but those templates are not portable to older fingerprint-only models such as K40.');
    }

    private function renderSummary(array $summary): void
    {
        $this->newLine();
        $this->info('Execution Summary');
        $this->line('Integration: '.$summary['integration_method']);
        $this->line('Employee: '.$summary['employee']['employee_id'].' '.$summary['employee']['name']);
        $this->line('Source: '.$summary['source']['name'].' ['.$summary['source']['ip_address'].']');
        $this->line('Templates found: '.$summary['template_count']);

        if (! empty($summary['udp']['warnings'])) {
            foreach ($summary['udp']['warnings'] as $warning) {
                $this->warn("Source UDP warning: {$warning}");
            }
        }

        $rows = [];
        foreach ($summary['targets'] as $targetSummary) {
            $rows[] = [
                $targetSummary['target']['name'].' ['.$targetSummary['target']['ip_address'].']',
                $targetSummary['queued'],
                $targetSummary['udp_uploaded'],
                $targetSummary['skipped'],
                $targetSummary['fallbacks'],
            ];
        }

        $this->newLine();
        $this->table(
            ['Target', 'Queued', 'UDP Uploaded', 'Skipped', 'Fallbacks'],
            $rows,
        );

        foreach ($summary['targets'] as $targetSummary) {
            foreach ($targetSummary['warnings'] as $warning) {
                $this->warn($targetSummary['target']['name'].': '.$warning);
            }

            foreach ($targetSummary['errors'] as $error) {
                $this->error($targetSummary['target']['name'].': '.$error);
            }
        }

        $this->newLine();
        $this->warn('Failure Modes');
        $this->line('1. Device connection failures: the command falls back to ADMS-stored templates or records a re-enrollment requirement.');
        $this->line('2. Missing target user: UDP mode creates the user with setUser(); ADMS mode queues SYNC_EMPLOYEES before SYNC_BIOMETRICS.');
        $this->line('3. Template incompatibility: model, platform, algorithm, and firmware checks can block direct transfer and trigger fallback.');
        $this->line('4. False success: a device may acknowledge a template write but still fail verification, so round-trip readback and physical validation are still required.');
    }
}
