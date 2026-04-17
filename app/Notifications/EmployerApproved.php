<?php

namespace App\Notifications;

use App\Models\Employer;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmployerApproved extends Notification
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
            ->subject('Your ForgeKin Account Has Been Verified')
            ->greeting($greeting)
            ->line('Great news — your company account (' . $this->employer->company_name . ') has been reviewed and verified by the ForgeKin team.')
            ->line('You can now log in and start posting jobs.')
            ->action('Log in to ForgeKin', url('/login'))
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
