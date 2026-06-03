<?php

namespace App\Notifications;

use App\Models\Freelancer;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to every Super-Admin / Admin when a new freelancer registers, so the
 * team is alerted both by email and in the in-app Support & Notification
 * Center (mirrors {@see NewEmployerRegistered}).
 */
class NewFreelancerRegistered extends Notification
{
    use Queueable;

    protected $freelancer;

    public function __construct(Freelancer $freelancer)
    {
        $this->freelancer = $freelancer;
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    private function fullName(): string
    {
        return trim(($this->freelancer->first_name ?? '') . ' ' . ($this->freelancer->last_name ?? '')) ?: 'A freelancer';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $adminName = $notifiable->first_name ?? 'Admin';

        return (new MailMessage)
            ->subject('New Freelancer Registration Notification')
            ->greeting("Hello {$adminName},")
            ->line('A new freelancer has just registered on ForgeKin.')
            ->line('Name: ' . $this->fullName())
            ->line('Email: ' . $this->freelancer->email)
            ->action('View Users', rtrim((string) config('app.admin_url'), '/') . '/users')
            ->line('Thank you.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'freelancer',
            'title' => 'New freelancer registered',
            'message' => $this->fullName() . ' just registered (' . $this->freelancer->email . ').',
            'url' => '/users',
            'freelancer_id' => $this->freelancer->id,
            'event' => 'new_freelancer_registered',
        ];
    }
}
