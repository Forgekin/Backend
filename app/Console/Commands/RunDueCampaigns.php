<?php

namespace App\Console\Commands;

use App\Services\CampaignDispatcher;
use Illuminate\Console\Command;

/**
 * Sends scheduled email campaigns whose time has arrived. Run on a cron via
 * the scheduler (`campaigns:run-due` is scheduled every minute in
 * routes/console.php), or invoked manually / by the admin "Run scheduled now"
 * action when no scheduler is configured.
 */
class RunDueCampaigns extends Command
{
    protected $signature = 'campaigns:run-due';

    protected $description = 'Send any scheduled email campaigns whose time has arrived.';

    public function handle(): int
    {
        $processed = CampaignDispatcher::runDue();

        $this->info($processed === 0
            ? 'No campaigns due.'
            : "Processed {$processed} campaign(s).");

        return self::SUCCESS;
    }
}
