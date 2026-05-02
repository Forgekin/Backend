<?php

namespace App\Notifications;

use App\Models\Employer;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmployerRegistered extends Notification
{
    use Queueable;

    protected $employer;

    public function __construct(Employer $employer)
    {
        $this->employer = $employer;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $firstName = $notifiable->first_name ?? 'there';

        return (new MailMessage)
            ->subject('Welcome to ForgeKin — your registration is being reviewed')
            ->greeting("Hello {$firstName},")
            ->line('Thanks for registering ' . $this->employer->company_name . ' on ForgeKin.')
            ->line('Your account has been received and is now pending review by our team. You will receive a follow-up email once it has been approved, after which you can log in and start posting jobs.')
            ->line('No further action is required from you at this stage.')
            ->line('Welcome aboard!');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'employer_id' => $this->employer->id,
            'company_name' => $this->employer->company_name,
        ];
    }
}
