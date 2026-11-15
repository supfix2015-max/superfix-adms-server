<?php

namespace App\Console\Commands;

use App\Services\ZenHrAttendanceOutbox;
use Illuminate\Console\Command;

/**
 * Rescan legacy/staged attendance that predates the current outbox cursor.
 * The old sender may already have exported these punches: quarantine every
 * new event for manual ZenHR reconciliation, never POST or alter dbo.
 */
final class BackfillZenHrPreCutoverHistoryCommand extends Command
{
    protected $signature = 'adms:zenhr-backfill-precutover
        {--limit=500 : Records per source per page (1-1000)}
        {--max-pages=100 : Safety limit per invocation (1-1000)}
        {--after-dbo-id=0 : Resume position for original dbo.Attendance}
        {--after-adms-id=0 : Resume position for adms.attendance_logs}
        {--apply : Stage new events for review; never POST to ZenHR}';

    protected $description = 'Rescan old SQL + ADMS punches between configured start and review boundary; quarantined and dry-run by default';

    public function handle(ZenHrAttendanceOutbox $outbox): int
    {
        try {
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
            $maxPages = filter_var($this->option('max-pages'), FILTER_VALIDATE_INT);
            $dbo = filter_var($this->option('after-dbo-id'), FILTER_VALIDATE_INT);
            $adms = filter_var($this->option('after-adms-id'), FILTER_VALIDATE_INT);
            if ($limit === false || $limit < 1 || $limit > 1000
                || $maxPages === false || $maxPages < 1 || $maxPages > 1000
                || $dbo === false || $dbo < 0 || $adms === false || $adms < 0) {
                throw new \InvalidArgumentException('Invalid limit/page/cursor options.');
            }

            $totals = ['dbo_read' => 0, 'adms_read' => 0, 'unique_new' => 0, 'already_present' => 0, 'needs_review' => 0];
            $hasMore = true;
            $previousDbo = -1;
            $previousAdms = -1;
            $range = '';
            for ($page = 1; $page <= $maxPages; $page++) {
                $result = $outbox->backfillPreCutover(
                    (int) $limit, (int) $dbo, (int) $adms, (bool) $this->option('apply')
                );
                $range = $result['range_local'];
                foreach (array_keys($totals) as $key) {
                    $totals[$key] += (int) $result[$key];
                }
                $previousDbo = (int) $dbo;
                $previousAdms = (int) $adms;
                $dbo = $result['after_dbo_id'];
                $adms = $result['after_adms_id'];
                $hasMore = $result['has_more'];
                if (! $hasMore || ($dbo === $previousDbo && $adms === $previousAdms)) {
                    break;
                }
            }

            $this->line('Range: '.$range);
            $this->line('Mode: '.($this->option('apply')
                ? 'STAGED IN ADMS FOR REVIEW, ZERO ZENHR POSTS'
                : 'DRY RUN, ZERO DATABASE WRITES OR ZENHR POSTS'));
            foreach ($totals as $key => $count) {
                $this->line($key.': '.$count);
            }
            $this->line('Resume cursor: --after-dbo-id='.$dbo.' --after-adms-id='.$adms);
            if ($hasMore) {
                $this->warn('More pages may remain. Resume with the displayed cursors.');
                return self::FAILURE;
            }
            $this->info('Pre-cutover scan complete. Historical events remain needs_review until ZenHR reconciliation.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Pre-cutover history scan failed: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
