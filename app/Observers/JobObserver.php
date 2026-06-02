<?php

namespace App\Observers;

use App\Models\Employer;
use App\Models\Freelancer;
use App\Models\Job;
use App\Models\User;
use App\Notifications\AdminJobStatusUpdated;
use App\Notifications\EmployerJobStatusUpdated;
use App\Notifications\FreelancerAcceptedJob;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class JobObserver
{
    /**
     * Statuses worth emailing the job's employer about. Creation ("new") and
     * "pending_approval" are intentionally excluded — the employer just did
     * those themselves.
     *
     * @var list<string>
     */
    protected array $notifiableStatuses = [
        'assigned',
        'in_progress',
        'on_hold',
        'done',
        'approved',
        'rejected',
    ];

    /**
     * Notify the employer and the admin team whenever a job moves to a notable
     * status, regardless of which code path triggered the change.
     */
    public function updated(Job $job): void
    {
        if (!$job->wasChanged('status')) {
            return;
        }

        $job->loadMissing('assignedFreelancer', 'employer');
        $previousStatus = $job->getOriginal('status');

        // The assigned freelancer accepting the job warrants a dedicated admin
        // alert (this status is not part of the generic status-change flow below).
        // The employer is still kept in the loop.
        if ($job->status === 'accepted') {
            $this->notifyAdmins(new FreelancerAcceptedJob($job), $job, 'Admin freelancer-accepted email failed');
            $this->notifyEmployer($job, $previousStatus);
            return;
        }

        if (!in_array($job->status, $this->notifiableStatuses, true)) {
            return;
        }

        // Confirmation to the job's employer.
        $this->notifyEmployer($job, $previousStatus);

        // Visibility alert to all admins (Super-Admin / Admin), attributing the
        // change to whoever made it (e.g. the system user who moved the stage).
        $this->notifyAdmins(new AdminJobStatusUpdated($job, $previousStatus, $this->resolveActorLabel()), $job, 'Admin job-status email failed');
    }

    /**
     * A human-readable label for whoever triggered the status change, used to
     * attribute the move in the admin notification. Null outside a request
     * (e.g. queue/console) where there is no authenticated actor.
     */
    protected function resolveActorLabel(): ?string
    {
        $actor = Auth::user();
        if (!$actor) {
            return null;
        }

        $name = trim((($actor->first_name ?? '') . ' ' . ($actor->last_name ?? '')))
            ?: ($actor->company_name ?? $actor->email ?? null);

        if ($actor instanceof User) {
            return ($name ?: 'A system user') . ' (system user)';
        }
        if ($actor instanceof Freelancer) {
            return ($name ?: 'The freelancer') . ' (freelancer)';
        }
        if ($actor instanceof Employer) {
            return ($name ?: 'The employer') . ' (employer)';
        }

        return $name;
    }

    /**
     * Email the job's employer about a status change, swallowing and logging any
     * failure so a mail problem never breaks the originating request.
     */
    protected function notifyEmployer(Job $job, ?string $previousStatus): void
    {
        if (!$job->employer) {
            return;
        }

        try {
            $job->employer->notify(new EmployerJobStatusUpdated($job, $previousStatus));
        } catch (\Throwable $e) {
            Log::error('Employer job-status email failed', [
                'job_id' => $job->id,
                'employer_id' => $job->employer->id ?? null,
                'status' => $job->status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send a notification to every admin (Super-Admin / Admin), swallowing and
     * logging any failure so a mail problem never breaks the originating request.
     */
    protected function notifyAdmins(object $notification, Job $job, string $logContext): void
    {
        try {
            $admins = User::whereHas('roles', function ($q) {
                $q->whereIn('name', ['Super-Admin', 'Admin']);
            })->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, $notification);
            }
        } catch (\Throwable $e) {
            Log::error($logContext, [
                'job_id' => $job->id,
                'status' => $job->status,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
