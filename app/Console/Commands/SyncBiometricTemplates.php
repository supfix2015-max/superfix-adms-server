<?php

namespace App\Console\Commands;

use App\Services\BiometricTemplateSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncBiometricTemplates extends Command
{
    protected $signature = 'adms:sync-biometric-templates
        {source_ip : Registered source device IP address}
        {target_ips* : One or more registered target device IP addresses}
        {--employee=* : Limit the sync to specific employee codes}
        {--prefer-udp : Try direct UDP getUserTemplate()/setUserTemplate() if a compatible SDK is installed}
        {--force : Overwrite existing target templates and bypass reusable command fingerprints}
        {--fallback=log : Fallback strategy when direct transfer is unsafe: log or remote-enroll}
        {--json : Output the final summary as JSON}';

    protected $description = 'Sync fingerprint and face templates between registered ZKTeco devices using the current ADMS project flow, with optional legacy UDP transfer.';

    public function handle(BiometricTemplateSyncService $syncService): int
    {
        $sourceIp = trim((string) $this->argument('source_ip'));
        $targetIps = collect((array) $this->argument('target_ips'))
            ->map(static fn (string $ip): string => trim($ip))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $employeeCodes = collect((array) $this->option('employee'))
            ->map(static fn (string $employeeCode): string => trim($employeeCode))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $fallback = strtolower(trim((string) $this->option('fallback')));
        if (! in_array($fallback, ['log', 'remote-enroll'], true)) {
            $this->error("Unsupported fallback [{$fallback}]. Use log or remote-enroll.");

            return self::INVALID;
        }

        $preferUdp = (bool) $this->option('prefer-udp');
        $force = (bool) $this->option('force');
        $json = (bool) $this->option('json');

        if (! $json) {
            $this->renderContext($sourceIp, $targetIps, $employeeCodes, $preferUdp, $fallback, $force);
            $this->renderProtocolNotes();
        }

        try {
            $summary = $syncService->sync($sourceIp, $targetIps, [
                'employee_codes' => $employeeCodes,
                'prefer_udp' => $preferUdp,
                'force' => $force,
                'fallback' => $fallback,
            ]);
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

    private function renderContext(string $sourceIp, array $targetIps, array $employeeCodes, bool $preferUdp, string $fallback, bool $force): void
    {
        $this->info('Biometric Template Sync');
        $this->line('Laravel: '.app()->version());
        $this->line('Project transport: ADMS push protocol with optional legacy UDP extraction/upload');
        $this->line("Source device IP: {$sourceIp}");
        $this->line('Target device IPs: '.implode(', ', $targetIps));
        $this->line('Employee filter: '.($employeeCodes === [] ? 'all employees on the source device' : implode(', ', $employeeCodes)));
        $this->line('Prefer UDP: '.($preferUdp ? 'yes' : 'no'));
        $this->line("Fallback: {$fallback}");
        $this->line('Force overwrite: '.($force ? 'yes' : 'no'));
    }

    private function renderProtocolNotes(): void
    {
        $this->newLine();
        $this->warn('Feasibility');
        $this->line('1. Fingerprint templates can sometimes be extracted and replayed between compatible legacy ZKTeco devices.');
        $this->line('2. Face templates are much less portable. A write can return success while verification still fails because the matcher format is firmware or platform specific.');
        $this->line('3. In this project, the reliable default is to replay templates already stored through ADMS and fall back to remote re-enrollment when compatibility checks fail.');

        $this->newLine();
        $this->warn('Legacy UDP Reference');
        $this->line('CMD_USERTEMP_RRQ = 0x0009');
        $this->line('CMD_USERTEMP_WRQ = 0x000A');
        $this->line("Header: pack('vvvv', \$command, \$checksum, \$sessionId, \$replyId)");
        $this->line("Read payload: pack('vv', \$uid, \$fingerIndex)");
        $this->line("Write payload: pack('vvCC', strlen(\$templateBytes), \$uid, \$fingerIndex, \$valid ? 1 : 0) . \$templateBytes");

        $this->newLine();
        $this->warn('SDK Examples');
        $this->line(<<<'PHP'
$rawFingerprint = $zk->getUserTemplate($uid, $fingerIndex);
$binaryPayload = pack('vvCC', strlen($templateBytes), $uid, $fingerIndex, 1) . $templateBytes;
$zk->setUserTemplate($binaryPayload);

$faceTemplates = $zk->getFaceData($uid);
$zk->setFaceData($uid, [
    50 => ['template' => $faceTemplateBytes],
]);
PHP);
    }

    private function renderSummary(array $summary): void
    {
        $this->newLine();
        $this->info('Execution Summary');
        $this->line('Integration used by this repo: '.$summary['integration_method']);
        $this->line('Source: '.$summary['source']['name'].' ['.$summary['source']['ip_address'].']');
        $this->line('Employees covered: '.$summary['employee_count']);
        $this->line('Templates available locally: '.$summary['template_count']);

        $udp = $summary['udp'];
        $this->line(
            sprintf(
                'UDP attempted: %s, SDK available: %s, templates downloaded: %d',
                $udp['attempted'] ? 'yes' : 'no',
                $udp['available'] ? 'yes' : 'no',
                (int) $udp['downloaded'],
            )
        );

        if (! empty($udp['warnings'])) {
            foreach ($udp['warnings'] as $warning) {
                $this->warn("UDP warning: {$warning}");
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
        $this->warn('Why direct transfer fails');
        $this->line('1. Template algorithms differ across product families, especially fingerprint algorithm revisions and face engines.');
        $this->line('2. Platform and firmware mismatches can accept a packet but reject the template at match time.');
        $this->line('3. Face data is often model-bound, encrypted, or stored in a vendor-specific format outside the legacy fingerprint packet path.');
        $this->line('4. A success acknowledgement only proves the device accepted the write request, not that on-device verification will succeed.');
        $this->line('5. When in doubt, use the remote re-enrollment fallback so the target terminal generates its own native template.');
    }
}
