<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * In-app (database) copy of an email broadcast / newsletter, so recipients also
 * see the announcement in their notifications and can read the full message in
 * a modal.
 *
 * Database-only: the branded email itself is sent separately by
 * {@see \App\Jobs\SendEmailCampaign}, so adding a 'mail' channel here would send
 * a duplicate. The full HTML is kept in `body` for the reader modal, with a
 * plain-text `message` excerpt for the notification list preview.
 */
class CampaignBroadcast extends Notification
{
    use Queueable;

    public function __construct(
        protected int $campaignId,
        protected string $subjectLine,
        protected string $body,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'broadcast',
            'title' => $this->subjectLine,
            'message' => Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($this->body))), 160),
            'body' => $this->body,
            'url' => null,
            'campaign_id' => $this->campaignId,
        ];
    }
}
