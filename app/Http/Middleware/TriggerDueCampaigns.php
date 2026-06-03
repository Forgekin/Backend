<?php

namespace App\Http\Middleware;

use App\Services\CampaignDispatcher;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Poor-man's cron" for scheduled email campaigns.
 *
 * Runs in terminate() — after the response has been sent to the client — so it
 * adds no latency to the request. {@see CampaignDispatcher::tick()} throttles
 * the actual work to once per minute via a cache lock, so even on a busy API
 * the cost is one cheap query a minute. This makes scheduled sends fire
 * automatically with no system cron and no queue worker.
 *
 * Disable it (e.g. once a real `schedule:run` cron is configured) by setting
 * CAMPAIGNS_AUTO_TICK=false.
 */
class TriggerDueCampaigns
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! env('CAMPAIGNS_AUTO_TICK', true)) {
            return;
        }

        CampaignDispatcher::tick();
    }
}
