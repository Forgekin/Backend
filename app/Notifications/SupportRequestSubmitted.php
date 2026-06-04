<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to support staff (Super-Admin / Admin) when a system user submits a
 * support request from the admin panel's Support & Notification Center.
 * Delivered both as a database notification (so it surfaces in their
 * Notifications tab) and an email (with Reply-To set to the sender so the
 * team can respond directly).
 */
class SupportRequestSubmitted extends Notification
{
    use Queueable;

    public function __construct(
        protected string $subjectLine,
        protected string $body,
        protected ?string $senderName = null,
        protected ?string $senderEmail = null,
        protected ?int $senderId = null,
        // Delivery channels. Defaults to mail + database. The public contact
        // form passes ['database'] only, since it already emails the support
        // inbox itself and shouldn't email every admin a second time.
        protected array $channels = ['mail', 'database'],
    ) {
    }
    

    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $firstName = $notifiable->first_name ?? 'there';
        $from = $this->senderName ?: 'A system user';

        $mail = (new MailMessage)
            ->subject('Support request: ' . $this->subjectLine)
            ->greeting("Hello {$firstName},")
            ->line($from . ' has sent a support request from the admin panel.')
            ->line('Subject: ' . $this->subjectLine)
            ->line('Message:')
            ->line($this->body);

        if ($this->senderEmail) {
            $mail->replyTo($this->senderEmail, $this->senderName ?: null)
                ->action('Reply to ' . $from, 'mailto:' . $this->senderEmail)
                ->line('Reply directly to this email to respond to ' . $from . '.');
        }

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            // Keys the Notification Center renders directly.
            'type' => 'support_request',
            'title' => 'Support request: ' . $this->subjectLine,
            'message' => $this->body,
            'url' => '/support',
            // Sender info so staff can reply by email straight from the list.
            'from' => $this->senderName,
            'email' => $this->senderEmail,
            'sender_id' => $this->senderId,
        ];
    }
}
