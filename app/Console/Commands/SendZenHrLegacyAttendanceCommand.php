<?php

namespace App\Console\Commands;

use App\Services\ZenHrLegacyAttendanceSender;
use Illuminate\Console\Command;

final class SendZenHrLegacyAttendanceCommand extends Command
{
    protected $signature = 'adms:zenhr-send-legacy
        {--limit=1 : Max punches per one verified ZenHR bulk POST, range 1-10}
        {--apply : Make exactly one POST, only when ZENHR_ATTENDANCE_POST_ENABLED=true}';

    protected $description = 'Old BioWeb-compatible employee/StatusId ZenHR bulk sender; dry-run by default';

    public function handle(ZenHrLegacyAttendanceSender $sender): int
    {
        try {
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
            if ($limit === false || $limit < 1 || $limit > 10) {
                $this->error('--limit must be between 1 and 10.');
                return self::FAILURE;
            }

            $plan = $sender->preview($limit);
            $this->table(['Legacy BioWeb-compatible ZenHR delivery', 'Result'], [
                ['Outbox pending', $plan['total_pending']],
                ['Valid employee ID + original StatusId + timezone', $plan['eligible']],
                ['Not found in ZenHR employee list', $plan['no_employee_mapping']],
                ['Ambiguous employee ID', $plan['ambiguous_employee']],
                ['Missing/invalid original SQL StatusId (0 or 1)', $plan['missing_or_non_binary_legacy_status']],
                ['Invalid timestamps', $plan['invalid_local_time']],
                ['Batch selected', count($plan['selected'])],
                ['Old BioWeb transaction ID configured', ((int) config('zenhr.attendance_transaction_id', 0) > 0) ? 'YES' : 'NO'],
                ['POST permission enabled', config('zenhr.attendance_post_enabled', false) ? 'YES' : 'NO'],
                ['Operation', $this->option('apply') ? 'REQUESTED SINGLE POST' : 'DRY RUN · ZERO POSTS'],
            ]);
            if ($plan['scan_incomplete']) {
                $this->error('Local scan incomplete; no POST.');
                return self::FAILURE;
            }
            if (! $this->option('apply')) {
                $this->warn('DRY RUN only. No attendance changed in SQL or ZenHR.');
                return self::SUCCESS;
            }
            if ($plan['selected'] === []) {
                $this->warn('No verified matching punches; no ZenHR POST.');
                return self::SUCCESS;
            }

            $this->warn('Submitting one bulk request with '.count($plan['selected']).' event(s).');
            $result = $sender->send($plan['selected']);
            $this->table(['ZenHR one-request result', 'Value'], [
                ['HTTP status', $result['http_status'] ?? 'UNKNOWN'],
                ['Accepted by API (202)', $result['accepted'] ? 'YES' : 'NO'],
                ['Events submitted', $result['submitted']],
                ['New outbox state', $result['outbox_state']],
            ]);

            if (! $result['accepted']) {
                $this->error('STOP: uncertain delivery. Check ZenHR; do not blindly retry this event.');
                return self::FAILURE;
            }
            $this->info('Accepted (HTTP 202). Verify visible attendance in ZenHR before any automatic sending.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            // Do not leak secrets, request JSON, sensitive person metadata or API bodies.
            $this->error('ZenHR delivery command halted: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
