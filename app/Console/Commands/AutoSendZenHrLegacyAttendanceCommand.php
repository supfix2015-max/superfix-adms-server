<?php

namespace App\Console\Commands;

use App\Services\ZenHrLegacyAttendanceSender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One supervised automatic batch per invocation; the existing
 * zenhr-outbox worker runs it once per minute only when opted in.
 */
final class AutoSendZenHrLegacyAttendanceCommand extends Command
{
    protected $signature = 'adms:zenhr-auto-send {--limit=10 : Punches per batch, max 10}';

    protected $description = 'Deliver one verified legacy-format ZenHR batch; stop on any unknown result';

    public function handle(ZenHrLegacyAttendanceSender $sender): int
    {
        try {
            if (! config('zenhr.attendance_auto_send', false)
                || ! config('zenhr.attendance_post_enabled', false)
                || ! config('zenhr.outbox_auto_collect', false)) {
                throw new RuntimeException(
                    'Auto-send disabled; require AUTO_SEND, POST_ENABLED, and OUTBOX_AUTO_COLLECT=true.'
                );
            }

            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
            if ($limit === false || $limit < 1 || $limit > 10) {
                throw new RuntimeException('Invalid batch limit, must be 1-10.');
            }

            $db = DB::connection('sqlsrv');
            // Fail closed after a network timeout, rejection, or crash;
            // ensure old inflight batches are investigated before any more.
            $unsafe = $db->table('adms.zenhr_attendance_outbox')
                ->whereIn('delivery_state', ['inflight', 'unknown', 'failed'])
                ->count();

            if ($unsafe > 0) {
                throw new RuntimeException(
                    'ZenHR sending HALTED: '.$unsafe.' unconfirmed/rejected punches require review.'
                );
            }

            $plan = $sender->preview($limit);
            if ($plan['scan_incomplete']) {
                throw new RuntimeException('Pending outbox scan incomplete; auto-send halted.');
            }
            if ($plan['selected'] === []) {
                $this->line('ZenHR: no eligible pending punches. No POST.');
                return self::SUCCESS;
            }

            $result = $sender->send($plan['selected']);
            if (! ($result['accepted'] ?? false)) {
                throw new RuntimeException('ZenHR delivery unconfirmed; auto-send HALTED until review.');
            }

            $this->info(
                'ZenHR accepted one bulk POST: HTTP '.($result['http_status'] ?? 'unknown').
                ', events '.($result['submitted'] ?? 0).'.'
            );
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            // Errors must not include credentials or sensitive vendor JSON.
            $this->error('ZenHR auto-send halted: '.$exception->getMessage());
            return self::FAILURE;
        }
    }
}
