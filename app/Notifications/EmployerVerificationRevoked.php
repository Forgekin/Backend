<?php

namespace App\Notifications;

use App\Models\Employer;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmployerVerificationRevoked extends Notification
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
        $name = trim(($this->employer->first_name ?? '') . ' ' . ($this->employer->last_name ?? ''));
        $greeting = $name !== '' ? "Hello {$name}," : 'Hello,';

        return (new MailMessage)
            ->subject('Your ForgeKin account access has been suspended')
            ->greeting($greeting)
            ->line('We are writing to let you know that the verification on your company account (' . $this->employer->company_name . ') has been revoked by the ForgeKin team.')
            ->line('You will no longer be able to log in or post jobs until your account is re-verified.')
            ->line('If you believe this was done in error, or you would like to discuss the next steps, please reply to this email and our team will get back to you.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'employer_id' => $this->employer->id,
            'company_name' => $this->employer->company_name,
        ];
    }
}
