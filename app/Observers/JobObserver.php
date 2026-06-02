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
     * Notify the employer (job owner), the admin team, and — for the dedicated
     * "accepted" case — surface the freelancer acceptance, whenever a job's
     * status changes, regardless of which code path triggered it.
     */
    public function updated(Job $job): void
    {
        if (!$job->wasChanged('status')) {
            return;
        }

        $job->loadMissing('assignedFreelancer', 'employer');
        $previousStatus = $job->getOriginal('status');

        // The job owner (employer) receives an email for EVERY status change to
        // one of their jobs — no status is excluded.
        $this->notifyEmployer($job, $previousStatus);

        // Admins: a dedicated alert when a freelancer accepts a job, otherwise a
        // generic status-change alert attributing who made the change.
        if ($job->status === 'accepted') {
            $this->notifyAdmins(new FreelancerAcceptedJob($job), $job, 'Admin freelancer-accepted email failed');
        } else {
            $this->notifyAdmins(new AdminJobStatusUpdated($job, $previousStatus, $this->resolveActorLabel()), $job, 'Admin job-status email failed');
        }
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
