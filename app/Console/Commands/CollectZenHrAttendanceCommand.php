<?php

namespace App\Console\Commands;

use App\Services\ZenHrAttendanceOutbox;
use Illuminate\Console\Command;

final class CollectZenHrAttendanceCommand extends Command
{
    protected $signature = 'adms:zenhr-outbox
        {--apply : Persist deduplicated source punches to AT2 outbox only}
        {--limit=500 : Maximum source rows read per source per run (1-1000)}
        {--stats : Display outbox states/cursors without scanning sources}';

    protected $description = 'AT2-only Nov 12+ attendance outbox staging; never posts to ZenHR';

    public function handle(ZenHrAttendanceOutbox $outbox): int
    {
        try {
            if ($this->option('stats')) {
                $stats = $outbox->stats();
                $this->line($stats['ready']
                    ? 'Operational ZenHR outbox provisioned'
                    : 'Missing dedicated ZenHR outbox/cursor tables; DBA setup required');
                if ($stats['ready']) {
                    $rows = [];
                    foreach ($stats['counts'] as $state => $count) {
                        $rows[] = [$state, (int) $count];
                    }
                    $this->table(['State', 'Punches'], $rows);
                    $this->line('Original DB cursor: '.(int) $stats['cursors']->last_dbo_id);
                    $this->line('ADMS staging cursor: '.(int) $stats['cursors']->last_adms_id);
                }
                $this->warn('No ZenHR request made; no attendance changed.');
                return $stats['ready'] ? self::SUCCESS : self::FAILURE;
            }

            $result = $outbox->collect(
                (int) $this->option('limit'),
                (bool) $this->option('apply')
            );
            $this->table(['Metric', 'Value'], array_map(
                static fn ($key, $value): array => [$key, (string) $value],
                array_keys($result), array_values($result)
            ));
            $this->warn('This command DOES NOT send punches to ZenHR. No employee matching was attempted.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            // No exception text from external OAuth/API data.
            $this->error('AT2 ZenHR outbox collection failed: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
