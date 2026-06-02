<?php

namespace App\Notifications;

use App\Models\Job;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed to admins (Super-Admin / Admin) when the assigned freelancer accepts
 * a job, so the team knows the freelancer has committed to the work.
 */
class FreelancerAcceptedJob extends Notification
{
    use Queueable;

    public function __construct(
        protected Job $job,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $firstName = $notifiable->first_name ?? 'there';

        $freelancer = $this->job->assignedFreelancer;
        $freelancerName = $freelancer
            ? (trim(($freelancer->first_name ?? '') . ' ' . ($freelancer->last_name ?? '')) ?: 'A freelancer')
            : 'A freelancer';

        $employer = $this->job->employer;
        $employerName = $employer
            ? ($employer->company_name
                ?: (trim(($employer->first_name ?? '') . ' ' . ($employer->last_name ?? '')) ?: 'an employer'))
            : 'an employer';

        $frontend = rtrim((string) config('app.frontend_url'), '/');

        return (new MailMessage)
            ->subject('A freelancer has accepted a job: ' . $this->job->title)
            ->greeting("Hello {$firstName},")
            ->line("{$freelancerName} has accepted the job they were assigned to.")
            ->line('Job: ' . $this->job->title)
            ->line('Employer: ' . $employerName)
            ->action('Review job', $frontend . '/admin/jobs/' . $this->job->id)
            ->line('Open the admin dashboard for full details.');
    }
    

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'freelancer_accepted_job',
            'job_id' => $this->job->id,
            'job_title' => $this->job->title,
            'employer_id' => $this->job->employer_id,
            'freelancer_id' => $this->job->assigned_freelancer_id,
        ];
    }
}
