<?php

namespace App\Console\Commands;

use App\Jobs\SendEmailCampaign;
use App\Models\EmailCampaign;
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
        $due = EmailCampaign::where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->get();

        if ($due->isEmpty()) {
            $this->info('No campaigns due.');
            return self::SUCCESS;
        }

        $useQueue = (bool) env('CAMPAIGNS_QUEUE', false);

        foreach ($due as $campaign) {
            $campaign->forceFill(['status' => 'queued'])->save();
            $useQueue
                ? SendEmailCampaign::dispatch($campaign->id)
                : SendEmailCampaign::dispatchSync($campaign->id);
            $this->line("Dispatched campaign #{$campaign->id}: {$campaign->subject}");
        }

        $this->info("Processed {$due->count()} campaign(s).");
        return self::SUCCESS;
    }
}
