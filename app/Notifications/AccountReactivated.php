<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to any account (freelancer, employer, or admin user) when an
 * administrator reactivates it. The body is account-agnostic.
 */
class AccountReactivated extends Notification
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
            ->subject('Your ForgeKin account has been reactivated')
            ->greeting("Hello {$firstName},")
            ->line('Good news — your ForgeKin account has been reactivated.')
            ->line('You can now log in and use the platform as usual.')
            ->action('Log in to ForgeKin', rtrim((string) config('app.frontend_url'), '/') . '/login')
            ->line('Welcome back!');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'account_id' => $notifiable->id ?? null,
            'event' => 'account_reactivated',
        ];
    }
}
