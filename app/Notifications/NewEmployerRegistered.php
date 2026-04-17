<?php

namespace App\Notifications;

use App\Models\Employer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewEmployerRegistered extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The employer instance.
     *
     * @var \App\Models\Employer
     */
    protected $employer;

    /**
     * Create a new notification instance.
     */
    public function __construct(Employer $employer)
    {
        $this->employer = $employer;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New Employer Registration Notification')
            ->greeting('Hello Admin,')
            ->line('A new employer has just registered on the system.')
            ->line('Company: ' . $this->employer->company_name)
            ->line('Email: ' . $this->employer->email)
            ->line('Business Type: ' . $this->employer->business_type)
            ->action('View Employers', url('/admin/employers')) // or your admin panel URL
            ->line('Thank you.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
