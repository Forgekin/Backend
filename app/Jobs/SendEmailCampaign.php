<?php

namespace App\Jobs;

use App\Models\Employer;
use App\Models\EmailCampaign;
use App\Models\Freelancer;
use App\Models\User;
use App\Notifications\CampaignBroadcast;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * Sends one email campaign to its resolved audience.
 *
 * Queue-ready: implements ShouldQueue, so once a worker is running it can be
 * dispatched asynchronously. Until then the app calls it with
 * `SendEmailCampaign::dispatchSync($id)`, which runs it inline (no worker
 * needed) — the "fallback" path.
 */
class SendEmailCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Allow long-running sends when processed by a worker. */
    public int $timeout = 3600;

    public function __construct(public int $campaignId)
    {
    }

    public function handle(): void
    {
        $campaign = EmailCampaign::find($this->campaignId);

        if (! $campaign) {
            return;
        }

        // Only send from a state that's meant to go out; guard double-runs.
        if (! in_array($campaign->status, ['queued', 'scheduled', 'sending', 'failed'], true)) {
            return;
        }

        $recipients = EmailCampaign::recipientsFor($campaign->audience);

        $campaign->forceFill([
            'status' => 'sending',
            'started_at' => $campaign->started_at ?? now(),
            'total_recipients' => $recipients->count(),
            'sent_count' => 0,
            'failed_count' => 0,
            'last_error' => null,
        ])->save();

        if ($recipients->isEmpty()) {
            $campaign->forceFill([
                'status' => 'sent',
                'completed_at' => now(),
            ])->save();
            return;
        }
        

        $logoPath = public_path('email/forgekin-logo.png');
        $hasLogo = is_file($logoPath);
        $sent = 0;
        $failed = 0;
        $lastError = null;

        foreach ($recipients as $r) {
            try {
                Mail::send([], [], function ($mail) use ($r, $campaign, $logoPath, $hasLogo) {
                    $logo = $hasLogo ? $mail->embed($logoPath) : null;
                    $mail->to($r['email'], $r['name'])
                        ->subject($campaign->subject)
                        ->html(self::wrapHtml($campaign->subject, $campaign->body, $logo));
                });
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                $lastError = $e->getMessage();
                Log::error('Campaign email failed', [
                    'campaign_id' => $campaign->id,
                    'to' => $r['email'],
                    'error' => $e->getMessage(),
                ]);
            }

            // Persist progress periodically so the UI can reflect it.
            if ((($sent + $failed) % 50) === 0) {
                $campaign->forceFill(['sent_count' => $sent, 'failed_count' => $failed])->save();
            }
        }

        // Also drop an in-app notification to every recipient so the broadcast
        // surfaces in their notifications (and is readable in a modal). Kept
        // independent of email delivery — a notification hiccup must not fail
        // the campaign.
        $this->storeInAppNotifications($campaign);

        $campaign->forceFill([
            'sent_count' => $sent,
            'failed_count' => $failed,
            'last_error' => $lastError,
            'status' => $sent > 0 ? 'sent' : 'failed',
            'completed_at' => now(),
        ])->save();
    }

    /**
     * Store the broadcast as a database notification on each recipient account
     * (Freelancer / Employer / User) for the campaign's audience. Chunked so it
     * scales to large audiences; failures are logged, never fatal.
     */
    protected function storeInAppNotifications(EmailCampaign $campaign): void
    {
        $notification = new CampaignBroadcast($campaign->id, $campaign->subject, $campaign->body);
        $audience = $campaign->audience;

        $queries = [];
        if (in_array($audience, ['freelancers', 'everyone'], true)) {
            $queries[] = Freelancer::whereNotNull('email');
        }
        if (in_array($audience, ['employers', 'everyone'], true)) {
            $queries[] = Employer::whereNotNull('email');
        }
        if (in_array($audience, ['system_users', 'everyone'], true)) {
            $queries[] = User::whereNotNull('email');
        }

        foreach ($queries as $query) {
            $query->chunkById(500, function ($rows) use ($notification, $campaign) {
                try {
                    Notification::sendNow($rows, $notification);
                } catch (\Throwable $e) {
                    Log::error('Campaign in-app notification failed', [
                        'campaign_id' => $campaign->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
        }
    }

    /**
     * Wrap the admin-authored body in the ForgeKin email shell. The body is
     * inserted as-is (campaign authors are trusted staff).
     */
    public static function wrapHtml(string $subject, string $body, ?string $logoSrc): string
    {
        $safeSubject = e($subject);
        $header = $logoSrc
            ? '<img src="' . $logoSrc . '" alt="ForgeKin" width="160" style="display:inline-block;height:auto;border:0;" />'
            : '<span style="color:#E9A319;font-size:22px;font-weight:bold;">ForgeKin</span>';

        return <<<HTML
            <div style="font-family:Arial,Helvetica,sans-serif;max-width:640px;margin:0 auto;color:#1c1c1e;">
                <div style="background:#1c1c1e;padding:24px 28px;border-radius:16px 16px 0 0;text-align:center;">
                    {$header}
                </div>
                <div style="border:1px solid #eee;border-top:none;padding:32px 28px;border-radius:0 0 16px 16px;">
                    <h1 style="margin:0 0 18px;font-size:21px;line-height:1.3;">{$safeSubject}</h1>
                    <div style="font-size:15px;line-height:1.7;color:#333;">{$body}</div>
                    <hr style="border:none;border-top:1px solid #eee;margin:28px 0 16px;">
                    <p style="margin:0;font-size:12px;color:#aaa;">
                        You're receiving this because you have a ForgeKin account.
                    </p>
                </div>
            </div>
        HTML;
    }
}
