<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to any account (freelancer, employer, or admin user) when an
 * administrator deactivates it. The body is account-agnostic.
 */
class AccountDeactivated extends Notification
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $firstName = $notifiable->first_name ?? 'there';

        return (new MailMessage)
            ->subject('Your ForgeKin account has been deactivated')
            ->greeting("Hello {$firstName},")
            ->line('Your ForgeKin account has been deactivated by an administrator.')
            ->line('While it is deactivated you will not be able to log in or use the platform.')
            ->line('If you believe this was a mistake or would like to discuss reactivation, please reply to this email and our team will get back to you.')
            ->line('Thank you.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'account_id' => $notifiable->id ?? null,
            'event' => 'account_deactivated',
        ];
    }
}
