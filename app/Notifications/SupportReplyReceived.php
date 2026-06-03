<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * In-app notification raised when support staff reply to a user's message.
 *
 * The reply email itself is sent separately by {@see \App\Http\Controllers\ContactController},
 * so this notification is database-only — it mirrors that email into the
 * recipient's in-app notification center (so freelancers/employers see every
 * support message both in their inbox and on the dashboard bell).
 */
class SupportReplyReceived extends Notification
{
    use Queueable;

    public function __construct(
        protected string $subject,
        protected string $body,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'support_reply',
            'title' => $this->subject,
            'message' => $this->body,
            'from' => 'ForgeKin Support',
        ];
    }
}
