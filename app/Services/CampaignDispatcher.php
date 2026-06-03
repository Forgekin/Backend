<?php

namespace App\Services;

use App\Jobs\SendEmailCampaign;
use App\Models\EmailCampaign;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Single source of truth for turning campaigns into actual sends.
 *
 * Sending runs through {@see SendEmailCampaign}: queued when a worker is
 * available (CAMPAIGNS_QUEUE=true), otherwise inline. The three entry points
 * — the admin "Run scheduled" action, the `campaigns:run-due` command, and the
 * automatic {@see tick()} trigger — all funnel through here so they behave
 * identically.
 */
class CampaignDispatcher
{
    /** Push sends onto the queue (needs a worker) or run them inline. */
    public static function useQueue(): bool
    {
        return (bool) env('CAMPAIGNS_QUEUE', false);
    }

    /** Dispatch one campaign for sending. */
    public static function dispatch(EmailCampaign $campaign): void
    {
        $campaign->forceFill(['status' => 'queued'])->save();

        if (self::useQueue()) {
            SendEmailCampaign::dispatch($campaign->id);
        } else {
            SendEmailCampaign::dispatchSync($campaign->id);
        }
    }

    /**
     * Process every scheduled campaign whose time has arrived.
     *
     * @return int how many campaigns were dispatched
     */
    public static function runDue(): int
    {
        $due = EmailCampaign::where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->get();

        $processed = 0;
        foreach ($due as $campaign) {
            try {
                self::dispatch($campaign);
                $processed++;
            } catch (\Throwable $e) {
                Log::error('Failed to dispatch due campaign', [
                    'id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $processed;
    }

    /**
     * No-cron auto trigger. Safe to call on every request: a cache lock limits
     * the actual due-check to at most once per minute regardless of traffic
     * volume, so scheduled campaigns still fire automatically when there is no
     * system cron driving the scheduler. When a real `schedule:run` cron IS
     * configured this simply finds nothing left to do — harmless either way.
     */
    public static function tick(): void
    {
        // Cache::add is atomic, so only the first caller in each 55s window
        // wins the lock and runs the check.
        if (! Cache::add('campaigns:due-tick', 1, 55)) {
            return;
        }

        try {
            self::runDue();
        } catch (\Throwable $e) {
            Log::error('Automatic due-campaign tick failed', ['error' => $e->getMessage()]);
        }
    }
}
