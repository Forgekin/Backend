<?php

namespace App\Http\Controllers;

use App\Models\Employer;
use App\Models\Freelancer;
use App\Models\FreelancerWithdrawal;
use App\Models\Job;
use App\Models\JobPayment;
use App\Models\JobReview;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FreelancerDashboardController extends Controller
{
    /**
     * Freelancer dashboard
     *
     * Aggregated stats, earnings breakdown, active jobs, paginated job history, and withdrawal summary for a freelancer. The authenticated freelancer may only access their own dashboard.
     *
     * @group Freelancer Dashboard
     * @authenticated
     *
     * @urlParam id integer required The freelancer ID. Example: 1
     * @queryParam page integer Page number for job_history. Example: 1
     * @queryParam per_page integer Job-history page size (max 50). Example: 10
     *
     * @response 200 scenario="Success" {"success":true,"data":{}}
     * @response 403 scenario="Unauthorized" {"success":false,"message":"Unauthorized."}
     * @response 404 scenario="Not found" {"message":"No query results for model [App\\Models\\Freelancer] 999"}
     */
    public function index(Request $request, int $id)
    {
        $freelancer = Freelancer::with(['skills', 'documents'])->findOrFail($id);

        if (auth()->id() !== $freelancer->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.'
            ], 403);
        }

        $currency = 'GHS';
        $now = CarbonImmutable::now();

        $statusCounts = Job::where('assigned_freelancer_id', $freelancer->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $jobsCompleted  = (int) ($statusCounts['done'] ?? 0);
        $jobsInProgress = (int) ($statusCounts['in_progress'] ?? 0);
        $jobsOnHold     = (int) ($statusCounts['on_hold'] ?? 0);
        $jobsAssigned   = (int) ($statusCounts['assigned'] ?? 0);
        $jobsCancelled  = 0;
        $totalJobs      = (int) $statusCounts->sum();

        $onTimeStats = Job::where('assigned_freelancer_id', $freelancer->id)
            ->where('status', 'done')
            ->whereNotNull('completed_at')
            ->selectRaw('SUM(CASE WHEN DATE(completed_at) <= deadline THEN 1 ELSE 0 END) as on_time, COUNT(*) as done')
            ->first();

        $completionRate = $totalJobs > 0 ? round(($jobsCompleted / $totalJobs) * 100, 2) : 0;
        $onTimeRate = ($onTimeStats && $onTimeStats->done > 0)
            ? round(($onTimeStats->on_time / $onTimeStats->done) * 100, 2)
            : 0;

        $reviewStats = JobReview::where('freelancer_id', $freelancer->id)
            ->selectRaw('AVG(stars) as avg_stars, COUNT(*) as total')
            ->first();

        $repeatClients = Job::where('assigned_freelancer_id', $freelancer->id)
            ->where('status', 'done')
            ->select('employer_id', DB::raw('COUNT(*) as total'))
            ->groupBy('employer_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        $paymentAgg = JobPayment::where('freelancer_id', $freelancer->id)
            ->selectRaw("
                SUM(CASE WHEN status = 'paid' THEN net ELSE 0 END) as total_paid_net,
                SUM(CASE WHEN status = 'paid' THEN gross ELSE 0 END) as total_paid_gross,
                SUM(CASE WHEN status = 'paid' THEN tax ELSE 0 END) as total_tax,
                SUM(CASE WHEN status = 'paid' THEN platform_fee ELSE 0 END) as total_platform_fee,
                SUM(CASE WHEN status = 'pending' THEN gross ELSE 0 END) as total_pending,
                SUM(gross) as total_earned
            ")
            ->first();

        $totalEarned  = (float) ($paymentAgg->total_earned ?? 0);
        $totalPaid    = (float) ($paymentAgg->total_paid_gross ?? 0);
        $totalPending = (float) ($paymentAgg->total_pending ?? 0);
        $tax          = (float) ($paymentAgg->total_tax ?? 0);
        $platformFee  = (float) ($paymentAgg->total_platform_fee ?? 0);
        $netEarned    = (float) ($paymentAgg->total_paid_net ?? 0);

        $totalWithdrawn = (float) FreelancerWithdrawal::where('freelancer_id', $freelancer->id)
            ->where('status', 'completed')
            ->sum('amount');
        $pendingWithdrawals = (float) FreelancerWithdrawal::where('freelancer_id', $freelancer->id)
            ->whereIn('status', ['pending', 'processing'])
            ->sum('amount');
        $withdrawable = max(0, $netEarned - $totalWithdrawn - $pendingWithdrawals);

        $hourlyAgg = Job::where('job_postings.assigned_freelancer_id', $freelancer->id)
            ->where('job_postings.rate_type', 'hourly')
            ->where('job_postings.status', 'done')
            ->leftJoin('job_hours', 'job_postings.id', '=', 'job_hours.job_id')
            ->leftJoin('job_payments', function ($j) {
                $j->on('job_postings.id', '=', 'job_payments.job_id')
                  ->where('job_payments.status', 'paid');
            })
            ->selectRaw("
                COUNT(DISTINCT job_postings.id) as jobs,
                COALESCE(SUM(DISTINCT job_payments.gross), 0) as earned,
                COALESCE(SUM(job_hours.hours), 0) as hours
            ")
            ->first();

        $fixedAgg = Job::where('job_postings.assigned_freelancer_id', $freelancer->id)
            ->where('job_postings.rate_type', 'fixed')
            ->where('job_postings.status', 'done')
            ->leftJoin('job_payments', function ($j) {
                $j->on('job_postings.id', '=', 'job_payments.job_id')
                  ->where('job_payments.status', 'paid');
            })
            ->selectRaw("
                COUNT(DISTINCT job_postings.id) as jobs,
                COALESCE(SUM(job_payments.gross), 0) as earned
            ")
            ->first();

        $totalHoursBilled = (float) ($hourlyAgg->hours ?? 0);

        $byPeriod = [
            'today'        => $this->periodTotals($freelancer->id, $now->startOfDay(), $now->endOfDay()),
            'this_week'    => $this->periodTotals($freelancer->id, $now->startOfWeek(), $now->endOfWeek()),
            'this_month'   => $this->periodTotals($freelancer->id, $now->startOfMonth(), $now->endOfMonth()),
            'last_month'   => $this->periodTotals(
                $freelancer->id,
                $now->subMonthNoOverflow()->startOfMonth(),
                $now->subMonthNoOverflow()->endOfMonth()
            ),
            'year_to_date' => $this->periodTotals($freelancer->id, $now->startOfYear(), $now->endOfDay()),
            'all_time'     => ['earned' => $totalPaid, 'jobs' => $jobsCompleted],
        ];

        $monthlyTrend = JobPayment::where('freelancer_id', $freelancer->id)
            ->where('status', 'paid')
            ->where('paid_at', '>=', $now->subMonths(5)->startOfMonth())
            ->orderBy('paid_at')
            ->get(['paid_at', 'gross'])
            ->groupBy(fn ($p) => $p->paid_at->format('Y-m'))
            ->map(fn ($rows, $month) => [
                'month'  => $month,
                'earned' => round((float) $rows->sum('gross'), 2),
                'jobs'   => $rows->count(),
            ])
            ->values();

        $topSkills = $this->topSkills($freelancer->id);
        $topClients = $this->topClients($freelancer->id);
        $activeJobs = $this->activeJobs($freelancer);

        $perPage = min(max((int) $request->input('per_page', 10), 1), 50);
        $jobHistory = Job::where('assigned_freelancer_id', $freelancer->id)
            ->whereIn('status', ['done', 'on_hold'])
            ->with(['employer', 'review', 'payments', 'hourLogs'])
            ->orderByDesc('completed_at')
            ->paginate($perPage);

        $lastWithdrawal = FreelancerWithdrawal::where('freelancer_id', $freelancer->id)
            ->orderByDesc('requested_at')
            ->first();

        return response()->json([
            'success' => true,
            'version' => '1.0',
            'data' => [
                'freelancer' => [
                    'id' => $freelancer->id,
                    'first_name' => $freelancer->first_name,
                    'last_name' => $freelancer->last_name,
                    'other_names' => $freelancer->other_names,
                    'full_name' => trim($freelancer->first_name . ' ' . ($freelancer->other_names ?: '') . ' ' . $freelancer->last_name),
                    'email' => $freelancer->email,
                    'contact' => $freelancer->contact,
                    'profession' => $freelancer->profession,
                    'location' => $freelancer->location,
                    'hourly_rate' => $freelancer->hourly_rate ? (float) $freelancer->hourly_rate : null,
                    'proficiency' => $freelancer->proficiency,
                    'profile_image_url' => $freelancer->profile_image ? asset('storage/' . ltrim(preg_replace('#^/?storage/#', '', $freelancer->profile_image), '/')) : null,
                    'verification_status' => $freelancer->email_verified_at ? 'verified' : 'pending',
                    'member_since' => optional($freelancer->created_at)->format('Y-m-d'),
                ],

                'stats' => [
                    'jobs_completed'        => $jobsCompleted,
                    'jobs_in_progress'      => $jobsInProgress,
                    'jobs_on_hold'          => $jobsOnHold,
                    'jobs_assigned'         => $jobsAssigned,
                    'jobs_cancelled'        => $jobsCancelled,
                    'total_jobs'            => $totalJobs,
                    'completion_rate'       => $completionRate,
                    'on_time_delivery_rate' => $onTimeRate,
                    'average_rating'        => $reviewStats && $reviewStats->total
                        ? round((float) $reviewStats->avg_stars, 2) : 0,
                    'total_reviews'         => (int) ($reviewStats->total ?? 0),
                    'repeat_clients'        => $repeatClients,
                ],

                'earnings' => [
                    'currency' => $currency,
                    'total_earned' => round($totalEarned, 2),
                    'total_pending' => round($totalPending, 2),
                    'total_paid' => round($totalPaid, 2),
                    'withdrawable_balance' => round($withdrawable, 2),
                    'withholding_tax' => round($tax, 2),
                    'platform_fees' => round($platformFee, 2),
                    'net_earned' => round($netEarned, 2),
                    'lifetime_hours_billed' => round($totalHoursBilled, 2),

                    'by_rate_type' => [
                        'hourly' => [
                            'jobs'   => (int) ($hourlyAgg->jobs ?? 0),
                            'earned' => round((float) ($hourlyAgg->earned ?? 0), 2),
                            'hours'  => round((float) ($hourlyAgg->hours ?? 0), 2),
                        ],
                        'fixed' => [
                            'jobs'   => (int) ($fixedAgg->jobs ?? 0),
                            'earned' => round((float) ($fixedAgg->earned ?? 0), 2),
                        ],
                    ],

                    'by_period' => $byPeriod,
                    'monthly_trend' => $monthlyTrend,
                ],

                'top_skills' => $topSkills,
                'top_clients' => $topClients,
                'active_jobs' => $activeJobs,

                'job_history' => [
                    'count' => $jobHistory->total(),
                    'page' => $jobHistory->currentPage(),
                    'per_page' => $jobHistory->perPage(),
                    'total_pages' => $jobHistory->lastPage(),
                    'items' => $jobHistory->getCollection()->map(fn (Job $job) => $this->formatHistoryItem($job, $currency))->values(),
                ],

                'withdrawals' => [
                    'currency' => $currency,
                    'total_withdrawn' => round($totalWithdrawn, 2),
                    'pending_withdrawals' => round($pendingWithdrawals, 2),
                    'last_withdrawal' => $lastWithdrawal ? [
                        'id' => $lastWithdrawal->reference ?? ('WD-' . $lastWithdrawal->id),
                        'amount' => (float) $lastWithdrawal->amount,
                        'method' => $lastWithdrawal->method,
                        'destination' => $lastWithdrawal->destination,
                        'status' => $lastWithdrawal->status,
                        'requested_at' => optional($lastWithdrawal->requested_at)->format('Y-m-d'),
                        'settled_at' => optional($lastWithdrawal->settled_at)->format('Y-m-d'),
                    ] : null,
                ],

                'meta' => [
                    'generated_at' => $now->toIso8601String(),
                    'cache_ttl_seconds' => 300,
                    'currency_symbol' => '₵',
                ],
            ],
        ]);
    }

    protected function periodTotals(int $freelancerId, $from, $to): array
    {
        $row = JobPayment::where('freelancer_id', $freelancerId)
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(gross), 0) as earned, COUNT(*) as jobs')
            ->first();

        return [
            'earned' => round((float) ($row->earned ?? 0), 2),
            'jobs'   => (int) ($row->jobs ?? 0),
        ];
    }

    protected function topSkills(int $freelancerId): array
    {
        $jobs = Job::where('job_postings.assigned_freelancer_id', $freelancerId)
            ->where('job_postings.status', 'done')
            ->leftJoin('job_payments', function ($j) {
                $j->on('job_postings.id', '=', 'job_payments.job_id')
                  ->where('job_payments.status', 'paid');
            })
            ->select('job_postings.skills', 'job_payments.gross')
            ->get();

        $agg = [];
        foreach ($jobs as $job) {
            foreach (array_filter(array_map('trim', explode(',', (string) $job->skills))) as $skill) {
                $agg[$skill] ??= ['name' => $skill, 'jobs_used_in' => 0, 'earnings' => 0.0];
                $agg[$skill]['jobs_used_in']++;
                $agg[$skill]['earnings'] += (float) ($job->gross ?? 0);
            }
        }

        usort($agg, fn ($a, $b) => $b['earnings'] <=> $a['earnings']);
        return array_slice(array_values(array_map(function ($s) {
            $s['earnings'] = round($s['earnings'], 2);
            return $s;
        }, $agg)), 0, 5);
    }

    protected function topClients(int $freelancerId): array
    {
        return Job::where('job_postings.assigned_freelancer_id', $freelancerId)
            ->where('job_postings.status', 'done')
            ->leftJoin('job_payments', function ($j) {
                $j->on('job_postings.id', '=', 'job_payments.job_id')
                  ->where('job_payments.status', 'paid');
            })
            ->select(
                'job_postings.employer_id',
                DB::raw('COUNT(DISTINCT job_postings.id) as jobs_completed'),
                DB::raw('COALESCE(SUM(job_payments.gross), 0) as total_earned'),
                DB::raw('MAX(job_postings.completed_at) as last_worked_on')
            )
            ->groupBy('job_postings.employer_id')
            ->orderByDesc('total_earned')
            ->limit(5)
            ->get()
            ->map(function ($row) {
                $employer = Employer::find($row->employer_id);
                $logo = $employer?->company_logo;
                return [
                    'employer_id' => (int) $row->employer_id,
                    'company_name' => $employer?->company_name,
                    'company_logo_url' => $logo
                        ? asset('storage/' . ltrim(preg_replace('#^/?storage/#', '', $logo), '/'))
                        : null,
                    'jobs_completed' => (int) $row->jobs_completed,
                    'total_earned' => round((float) $row->total_earned, 2),
                    'last_worked_on' => $row->last_worked_on
                        ? date('Y-m-d', strtotime($row->last_worked_on))
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    protected function activeJobs(Freelancer $freelancer): array
    {
        $jobs = Job::where('assigned_freelancer_id', $freelancer->id)
            ->whereIn('status', ['assigned', 'in_progress'])
            ->with(['employer', 'hourLogs'])
            ->get();

        return $jobs->map(function (Job $job) {
            $hoursLogged = (float) $job->hourLogs->sum('hours');
            $rate = (float) ($job->agreed_rate ?? $job->employer?->hourly_rate ?? 0);
            $earningsSoFar = $job->rate_type === 'hourly'
                ? round($hoursLogged * $rate, 2)
                : 0.0;

            $progress = 0;
            if ($job->actual_start_date && $job->deadline) {
                $total = $job->actual_start_date->diffInDays($job->deadline) ?: 1;
                $elapsed = $job->actual_start_date->diffInDays(now());
                $progress = min(100, max(0, (int) round(($elapsed / $total) * 100)));
            }

            return [
                'id' => $job->id,
                'title' => $job->title,
                'employer' => [
                    'id' => $job->employer?->id,
                    'company_name' => $job->employer?->company_name,
                    'company_logo_url' => $job->employer?->company_logo
                        ? asset('storage/' . ltrim(preg_replace('#^/?storage/#', '', $job->employer->company_logo), '/'))
                        : null,
                ],
                'status' => $job->status,
                'rate_type' => $job->rate_type,
                'rate' => $job->rate_type === 'hourly' ? $rate : null,
                'fixed_budget' => $job->rate_type === 'fixed' ? (float) ($job->max_budget ?? 0) : null,
                'hours_logged' => $job->rate_type === 'hourly' ? round($hoursLogged, 2) : null,
                'earnings_so_far' => $earningsSoFar,
                'deadline' => optional($job->deadline)->format('Y-m-d'),
                'progress_percent' => $progress,
                'shift_type' => $job->shift_type,
            ];
        })->values()->all();
    }

    protected function formatHistoryItem(Job $job, string $currency): array
    {
        $payment = $job->payments->firstWhere('status', 'paid') ?? $job->payments->first();
        $hoursLogged = (float) $job->hourLogs->sum('hours');
        $onTime = $job->completed_at && $job->deadline
            ? $job->completed_at->startOfDay()->lte($job->deadline->startOfDay())
            : null;
        $delayDays = ($onTime === false && $job->completed_at && $job->deadline)
            ? $job->deadline->diffInDays($job->completed_at->startOfDay())
            : null;

        return [
            'id' => $job->id,
            'title' => $job->title,
            'description_excerpt' => $job->description
                ? \Illuminate\Support\Str::limit($job->description, 140)
                : null,
            'skills' => array_values(array_filter(array_map('trim', explode(',', (string) $job->skills)))),
            'employer' => [
                'id' => $job->employer?->id,
                'company_name' => $job->employer?->company_name,
                'company_logo_url' => $job->employer?->company_logo
                    ? asset('storage/' . ltrim(preg_replace('#^/?storage/#', '', $job->employer->company_logo), '/'))
                    : null,
            ],
            'status' => $job->status,
            'rate_type' => $job->rate_type,
            'rate' => $job->rate_type === 'hourly' ? (float) ($job->agreed_rate ?? 0) : null,
            'fixed_budget' => $job->rate_type === 'fixed' ? (float) ($job->max_budget ?? 0) : null,
            'hours_logged' => $job->rate_type === 'hourly' ? round($hoursLogged, 2) : null,
            'amount_earned' => (float) ($payment?->gross ?? 0),
            'currency' => $currency,
            'assigned_at' => optional($job->assigned_at)->format('Y-m-d'),
            'started_at' => optional($job->actual_start_date)->format('Y-m-d'),
            'completed_at' => optional($job->completed_at)->format('Y-m-d'),
            'duration_days' => ($job->actual_start_date && $job->completed_at)
                ? $job->actual_start_date->diffInDays($job->completed_at)
                : null,
            'on_time' => $onTime,
            'delay_days' => $delayDays,
            'rating' => $job->review ? [
                'stars' => (int) $job->review->stars,
                'review' => $job->review->review_text,
            ] : null,
            'payment' => $payment ? [
                'status' => $payment->status,
                'paid_at' => optional($payment->paid_at)->format('Y-m-d'),
                'invoice_id' => $payment->invoice_id,
                'gross' => round((float) $payment->gross, 2),
                'platform_fee' => round((float) $payment->platform_fee, 2),
                'tax' => round((float) $payment->tax, 2),
                'net' => round((float) $payment->net, 2),
            ] : null,
        ];
    }
}
