<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Compatibility alias for the canonical all-employees dry-run importer.
 * Keeps the two preview commands consistent when incomplete legacy rows exist.
 */
class PreviewLegacyEmployeeMergeCommand extends Command
{
    protected $signature = 'adms:preview-legacy-employee-merge';

    protected $description = 'Preview ALL legacy employee imports, including missing badges and names (read-only)';

    public function handle(): int
    {
        // The import command is read-only unless the --apply flag is passed.
        return $this->call('adms:import-legacy-employees');
    }
}
