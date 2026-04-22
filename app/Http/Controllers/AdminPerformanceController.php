<?php

namespace App\Http\Controllers;

use App\Models\Employer;
use App\Models\Freelancer;
use App\Models\FreelancerWithdrawal;
use App\Models\Job;
use App\Models\JobHour;
use App\Models\JobPayment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminPerformanceController extends Controller
{
    /**
     * Admin performance dashboard
     *
     * Platform-wide performance + financial overview for admin / super-admin. Includes totals (jobs, freelancers, employers), financials (gross, platform profit, tax), trend charts, top freelancers, top employers, per-freelancer breakdown, recent jobs, and actionable alerts.
     *
     * @group Admin Performance
     * @authenticated
     *
     * @queryParam from date Optional ISO date (YYYY-MM-DD). Example: 2026-01-01
     * @queryParam to date Optional ISO date (YYYY-MM-DD). Example: 2026-04-18
     * @queryParam preset string Shortcut: today, this_week, this_month, last_month, last_30d, ytd, all_time. Example: ytd
     * @queryParam page integer Page for freelancer_breakdown + recent_jobs. Example: 1
     * @queryParam per_page integer Items per page (max 50). Example: 20
     *
     * @response 200 scenario="Success" {"success":true,"data":{}}
     * @response 403 scenario="Forbidden" {"message":"User does not have the right permissions."}
     */
    public function index(Request $request)
    {
        $currency = 'GHS';
        $now = CarbonImmutable::now();

        [$from, $to, $preset] = $this->resolveRange($request, $now);
        $perPage = min(max((int) $request->input('per_page', 20), 1), 50);

        $headline = $this->headline($now);
        $jobs = $this->jobs($from, $to);
        $financials = $this->financials($from, $to);
        $trends = $this->trends($now);
        $topFreelancers = $this->topFreelancers($from, $to);
        $topEmployers = $this->topEmployers($from, $to);
        $freelancerBreakdown = $this->freelancerBreakdown($request, $perPage);
        $recentJobs = $this->recentJobs($request, $perPage, $currency);
        $alerts = $this->alerts();

        return response()->json([
            'success' => true,
            'version' => '1.0',
            'data' => [
                'filters' => [
                    'date_range' => [
                        'from' => $from?->toDateString(),
                        'to'   => $to?->toDateString(),
                        'preset' => $preset,
                    ],
                    'currency' => $currency,
                    'currency_symbol' => '₵',
                ],
                'headline' => $headline,
                'jobs' => $jobs,
                'financials' => $financials,
                'trends' => $trends,
                'top_freelancers' => $topFreelancers,
                'top_employers' => $topEmployers,
                'freelancer_breakdown' => $freelancerBreakdown,
                'recent_jobs' => $recentJobs,
                'alerts' => $alerts,
                'meta' => [
                    'generated_at' => $now->toIso8601String(),
                    'cache_ttl_seconds' => 180,
                    'queried_by' => [
                        'id' => auth()->id(),
                        'email' => auth()->user()?->email,
                        'role' => auth()->user()?->getRoleNames()->first(),
                    ],
                ],
            ],
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    protected function resolveRange(Request $request, CarbonImmutable $now): array
    {
        $preset = $request->input('preset');

        if ($request->filled('from') || $request->filled('to')) {
            $from = $request->filled('from') ? CarbonImmutable::parse($request->input('from'))->startOfDay() : $now->startOfYear();
            $to   = $request->filled('to') ? CarbonImmutable::parse($request->input('to'))->endOfDay() : $now->endOfDay();
            return [$from, $to, $preset ?? 'custom'];
        }

        return match ($preset) {
            'today'      => [$now->startOfDay(), $now->endOfDay(), 'today'],
            'this_week'  => [$now->startOfWeek(), $now->endOfWeek(), 'this_week'],
            'this_month' => [$now->startOfMonth(), $now->endOfMonth(), 'this_month'],
            'last_month' => [
                $now->subMonthNoOverflow()->startOfMonth(),
                $now->subMonthNoOverflow()->endOfMonth(),
                'last_month',
            ],
            'last_30d'   => [$now->subDays(30)->startOfDay(), $now->endOfDay(), 'last_30d'],
            'all_time'   => [null, null, 'all_time'],
            default      => [$now->startOfYear(), $now->endOfDay(), 'year_to_date'],
        };
    }

    protected function headline(CarbonImmutable $now): array
    {
        $thirtyDaysAgo = $now->subDays(30);
        $monthStart = $now->startOfMonth();

        $activeFreelancers = JobHour::where('logged_for', '>=', $thirtyDaysAgo)
            ->distinct('freelancer_id')
            ->count('freelancer_id');

        $activeEmployers = Job::where('updated_at', '>=', $thirtyDaysAgo)
            ->distinct('employer_id')
            ->count('employer_id');

        return [
            'total_freelancers'               => Freelancer::count(),
            'active_freelancers_30d'          => $activeFreelancers,
            'total_employers'                 => Employer::count(),
            'active_employers_30d'            => $activeEmployers,
            'new_freelancers_this_month'      => Freelancer::where('created_at', '>=', $monthStart)->count(),
            'new_employers_this_month'        => Employer::where('created_at', '>=', $monthStart)->count(),
            'pending_employer_verifications'  => Employer::where('verification_status', 'inactive')->count(),
        ];
    }

    protected function jobs(?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $base = Job::query();
        if ($from && $to) {
            $base->whereBetween('job_postings.created_at', [$from, $to]);
        }

        $byStatus = (clone $base)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($v) => (int) $v);

        $totalPosted    = (int) $byStatus->sum();
        $totalAssigned  = (clone $base)->whereNotNull('assigned_freelancer_id')->count();
        $totalCompleted = (int) ($byStatus['done'] ?? 0);
        $totalInProgress = (int) ($byStatus['in_progress'] ?? 0);
        $totalOnHold    = (int) ($byStatus['on_hold'] ?? 0);
        $totalPendingApproval = (int) ($byStatus['pending_approval'] ?? 0);

        $onTime = (clone $base)
            ->where('status', 'done')
            ->whereNotNull('completed_at')
            ->whereColumn('completed_at', '<=', 'deadline')
            ->count();

        $avgCompletionDays = (clone $base)
            ->where('status', 'done')
            ->whereNotNull('completed_at')
            ->whereNotNull('actual_start_date')
            ->get(['actual_start_date', 'completed_at'])
            ->map(fn ($j) => $j->actual_start_date->diffInDays($j->completed_at))
            ->avg();

        $hourlyJobs = (clone $base)
            ->leftJoin('job_payments', function ($j) {
                $j->on('job_postings.id', '=', 'job_payments.job_id')
                  ->where('job_payments.status', 'paid');
            })
            ->leftJoin('job_hours', 'job_postings.id', '=', 'job_hours.job_id')
            ->where('job_postings.rate_type', 'hourly')
            ->where('job_postings.status', 'done')
            ->selectRaw('
                COUNT(DISTINCT job_postings.id) as jobs,
                COALESCE(SUM(DISTINCT job_payments.gross), 0) as earned,
                COALESCE(SUM(job_hours.hours), 0) as hours
            ')
            ->first();

        $fixedJobs = (clone $base)
            ->leftJoin('job_payments', function ($j) {
                $j->on('job_postings.id', '=', 'job_payments.job_id')
                  ->where('job_payments.status', 'paid');
            })
            ->where('job_postings.rate_type', 'fixed')
            ->where('job_postings.status', 'done')
            ->selectRaw('
                COUNT(DISTINCT job_postings.id) as jobs,
                COALESCE(SUM(job_payments.gross), 0) as earned
            ')
            ->first();

        return [
            'total_jobs_posted'           => $totalPosted,
            'total_jobs_assigned'         => $totalAssigned,
            'total_jobs_completed'        => $totalCompleted,
            'total_jobs_in_progress'      => $totalInProgress,
            'total_jobs_on_hold'          => $totalOnHold,
            'total_jobs_pending_approval' => $totalPendingApproval,
            'assignment_rate'   => $totalPosted > 0 ? round(($totalAssigned / $totalPosted) * 100, 2) : 0,
            'completion_rate'   => $totalAssigned > 0 ? round(($totalCompleted / $totalAssigned) * 100, 2) : 0,
            'on_time_delivery_rate' => $totalCompleted > 0 ? round(($onTime / $totalCompleted) * 100, 2) : 0,
            'average_completion_days' => $avgCompletionDays !== null ? round((float) $avgCompletionDays, 2) : 0,

            'by_status' => $byStatus->toArray(),

            'by_rate_type' => [
                'hourly' => [
                    'jobs'         => (int) ($hourlyJobs->jobs ?? 0),
                    'earned'       => round((float) ($hourlyJobs->earned ?? 0), 2),
                    'hours_billed' => round((float) ($hourlyJobs->hours ?? 0), 2),
                ],
                'fixed' => [
                    'jobs'   => (int) ($fixedJobs->jobs ?? 0),
                    'earned' => round((float) ($fixedJobs->earned ?? 0), 2),
                ],
            ],
        ];
    }

    protected function financials(?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $payments = JobPayment::query();
        if ($from && $to) {
            $payments->whereBetween('created_at', [$from, $to]);
        }

        $agg = (clone $payments)
            ->selectRaw("
                SUM(CASE WHEN status = 'paid' THEN gross ELSE 0 END) as paid_gross,
                SUM(CASE WHEN status = 'paid' THEN platform_fee ELSE 0 END) as platform_profit,
                SUM(CASE WHEN status = 'paid' THEN tax ELSE 0 END) as taxes,
                SUM(CASE WHEN status = 'paid' THEN net ELSE 0 END) as net_paid,
                SUM(CASE WHEN status = 'pending' THEN gross ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'refunded' THEN gross ELSE 0 END) as refunded,
                SUM(CASE WHEN status = 'disputed' THEN gross ELSE 0 END) as disputed,
                COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid_count
            ")
            ->first();

        $grossTransacted   = (float) ($agg->paid_gross ?? 0);
        $platformProfit    = (float) ($agg->platform_profit ?? 0);
        $taxesCollected    = (float) ($agg->taxes ?? 0);
        $netPaid           = (float) ($agg->net_paid ?? 0);
        $pending           = (float) ($agg->pending ?? 0);
        $paidCount         = (int) ($agg->paid_count ?? 0);

        $totalWithdrawn = (float) FreelancerWithdrawal::where('status', 'completed')->sum('amount');
        $outstandingWithdrawals = (float) FreelancerWithdrawal::whereIn('status', ['pending', 'processing'])->sum('amount');
        $withdrawableBalance = max(0, $netPaid - $totalWithdrawn - $outstandingWithdrawals);

        $grosses = (clone $payments)->where('status', 'paid')->pluck('gross')->map(fn ($v) => (float) $v)->sort()->values();
        $median = $this->median($grosses);

        return [
            'currency' => 'GHS',
            'gross_transacted'              => round($grossTransacted, 2),
            'freelancer_earnings_paid'      => round($netPaid, 2),
            'freelancer_earnings_pending'   => round($pending, 2),
            'platform_profit'               => round($platformProfit, 2),
            'taxes_collected'               => round($taxesCollected, 2),
            'total_withdrawn_by_freelancers'        => round($totalWithdrawn, 2),
            'outstanding_withdrawals'               => round($outstandingWithdrawals, 2),
            'withdrawable_balance_across_freelancers' => round($withdrawableBalance, 2),
            'average_job_value' => $paidCount > 0 ? round($grossTransacted / $paidCount, 2) : 0,
            'median_job_value'  => round($median, 2),
            'refunded_amount'   => round((float) ($agg->refunded ?? 0), 2),
            'disputed_amount'   => round((float) ($agg->disputed ?? 0), 2),
            'profit_margin_percent'  => $grossTransacted > 0 ? round(($platformProfit / $grossTransacted) * 100, 2) : 0,
        ];
    }

    protected function trends(CarbonImmutable $now): array
    {
        $sixMonthsAgo = $now->subMonths(5)->startOfMonth();

        $monthly = JobPayment::where('status', 'paid')
            ->where('paid_at', '>=', $sixMonthsAgo)
            ->orderBy('paid_at')
            ->get(['paid_at', 'gross', 'platform_fee', 'job_id'])
            ->groupBy(fn ($p) => $p->paid_at->format('Y-m'))
            ->map(fn ($rows, $month) => [
                'month' => $month,
                'gross' => round((float) $rows->sum('gross'), 2),
                'platform_profit' => round((float) $rows->sum('platform_fee'), 2),
                'jobs_completed' => $rows->pluck('job_id')->unique()->count(),
            ])
            ->values();

        return [
            'monthly' => $monthly,
        ];
    }

    protected function topFreelancers(?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $payments = JobPayment::where('status', 'paid');
        if ($from && $to) {
            $payments->whereBetween('paid_at', [$from, $to]);
        }

        return $payments
            ->select(
                'freelancer_id',
                DB::raw('COUNT(DISTINCT job_id) as jobs_completed'),
                DB::raw('COALESCE(SUM(gross), 0) as gross_earned'),
                DB::raw('COALESCE(SUM(net), 0) as net_earned'),
                DB::raw('COALESCE(SUM(platform_fee), 0) as profit')
            )
            ->groupBy('freelancer_id')
            ->orderByDesc('gross_earned')
            ->limit(5)
            ->get()
            ->map(function ($row) {
                $f = Freelancer::find($row->freelancer_id);
                if (!$f) { return null; }

                $reviews = $f->reviews()->selectRaw('AVG(stars) as avg_stars, COUNT(*) as total')->first();
                $active = Job::where('assigned_freelancer_id', $f->id)
                    ->whereIn('status', ['assigned', 'in_progress'])->count();
                $onTimeStats = Job::where('assigned_freelancer_id', $f->id)
                    ->where('status', 'done')
                    ->whereNotNull('completed_at')
                    ->selectRaw('SUM(CASE WHEN DATE(completed_at) <= deadline THEN 1 ELSE 0 END) as on_time, COUNT(*) as total')
                    ->first();

                return [
                    'id' => $f->id,
                    'full_name' => $this->fullName($f),
                    'profession' => $f->profession,
                    'profile_image_url' => $this->fileUrl($f->profile_image),
                    'jobs_completed' => (int) $row->jobs_completed,
                    'gross_earned' => round((float) $row->gross_earned, 2),
                    'net_earned' => round((float) $row->net_earned, 2),
                    'platform_profit_from_freelancer' => round((float) $row->profit, 2),
                    'average_rating' => $reviews && $reviews->total ? round((float) $reviews->avg_stars, 2) : 0,
                    'on_time_rate' => $onTimeStats && $onTimeStats->total > 0
                        ? round(($onTimeStats->on_time / $onTimeStats->total) * 100, 2) : 0,
                    'active_jobs' => $active,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    protected function topEmployers(?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $query = Job::query();
        if ($from && $to) {
            $query->whereBetween('job_postings.created_at', [$from, $to]);
        }

        return $query
            ->leftJoin('job_payments', function ($j) {
                $j->on('job_postings.id', '=', 'job_payments.job_id')
                  ->where('job_payments.status', 'paid');
            })
            ->select(
                'job_postings.employer_id',
                DB::raw('COUNT(DISTINCT job_postings.id) as jobs_posted'),
                DB::raw('SUM(CASE WHEN job_postings.status = \'done\' THEN 1 ELSE 0 END) as jobs_completed'),
                DB::raw('COALESCE(SUM(DISTINCT job_payments.gross), 0) as total_spend'),
                DB::raw('COUNT(DISTINCT job_postings.assigned_freelancer_id) as unique_freelancers_hired'),
                DB::raw('MAX(job_postings.updated_at) as last_activity')
            )
            ->groupBy('job_postings.employer_id')
            ->orderByDesc('total_spend')
            ->limit(5)
            ->get()
            ->map(function ($row) {
                $e = Employer::find($row->employer_id);
                if (!$e) { return null; }
                return [
                    'id' => $e->id,
                    'company_name' => $e->company_name,
                    'company_logo_url' => $this->fileUrl($e->company_logo),
                    'jobs_posted' => (int) $row->jobs_posted,
                    'jobs_completed' => (int) $row->jobs_completed,
                    'total_spend' => round((float) $row->total_spend, 2),
                    'unique_freelancers_hired' => (int) $row->unique_freelancers_hired,
                    'last_activity' => $row->last_activity
                        ? date('Y-m-d', strtotime($row->last_activity)) : null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    protected function freelancerBreakdown(Request $request, int $perPage): array
    {
        $paginator = Freelancer::orderByDesc('created_at')->paginate($perPage);

        $items = $paginator->getCollection()->map(function (Freelancer $f) {
            $statusCounts = Job::where('assigned_freelancer_id', $f->id)
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            $assigned = (int) $statusCounts->sum();
            $completed = (int) ($statusCounts['done'] ?? 0);
            $inProgress = (int) ($statusCounts['in_progress'] ?? 0);

            $onTime = Job::where('assigned_freelancer_id', $f->id)
                ->where('status', 'done')
                ->whereNotNull('completed_at')
                ->selectRaw('SUM(CASE WHEN DATE(completed_at) <= deadline THEN 1 ELSE 0 END) as on_time, COUNT(*) as total')
                ->first();

            $reviews = $f->reviews()->selectRaw('AVG(stars) as avg_stars, COUNT(*) as total')->first();

            $payAgg = JobPayment::where('freelancer_id', $f->id)
                ->selectRaw("
                    SUM(CASE WHEN status = 'paid' THEN gross ELSE 0 END) as gross,
                    SUM(CASE WHEN status = 'paid' THEN net ELSE 0 END) as net,
                    SUM(CASE WHEN status = 'pending' THEN gross ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'paid' THEN platform_fee ELSE 0 END) as profit,
                    SUM(CASE WHEN status = 'paid' THEN tax ELSE 0 END) as tax
                ")->first();

            return [
                'id' => $f->id,
                'full_name' => $this->fullName($f),
                'email' => $f->email,
                'profession' => $f->profession,
                'verification_status' => $f->email_verified_at ? 'verified' : 'pending',
                'joined_at' => optional($f->created_at)->format('Y-m-d'),
                'jobs_assigned' => $assigned,
                'jobs_completed' => $completed,
                'jobs_in_progress' => $inProgress,
                'jobs_cancelled' => 0,
                'completion_rate' => $assigned > 0 ? round(($completed / $assigned) * 100, 2) : 0,
                'on_time_rate' => $onTime && $onTime->total > 0
                    ? round(($onTime->on_time / $onTime->total) * 100, 2) : 0,
                'average_rating' => $reviews && $reviews->total ? round((float) $reviews->avg_stars, 2) : 0,
                'total_reviews' => (int) ($reviews->total ?? 0),
                'gross_earned' => round((float) ($payAgg->gross ?? 0), 2),
                'net_earned' => round((float) ($payAgg->net ?? 0), 2),
                'pending_payout' => round((float) ($payAgg->pending ?? 0), 2),
                'platform_profit_from_freelancer' => round((float) ($payAgg->profit ?? 0), 2),
                'taxes_withheld_from_freelancer' => round((float) ($payAgg->tax ?? 0), 2),
                'last_active_at' => optional($f->updated_at)->toIso8601String(),
            ];
        })->values();

        return [
            'count' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total_pages' => $paginator->lastPage(),
            'items' => $items,
        ];
    }

    protected function recentJobs(Request $request, int $perPage, string $currency): array
    {
        $paginator = Job::with(['employer', 'assignedFreelancer', 'payments', 'review', 'hourLogs'])
            ->orderByDesc('updated_at')
            ->paginate($perPage);

        $items = $paginator->getCollection()->map(function (Job $job) use ($currency) {
            $payment = $job->payments->firstWhere('status', 'paid') ?? $job->payments->first();
            $hours = (float) $job->hourLogs->sum('hours');
            $flags = $this->flagsFor($job);

            return [
                'id' => $job->id,
                'title' => $job->title,
                'description_excerpt' => $job->description ? Str::limit($job->description, 140) : null,
                'skills' => array_values(array_filter(array_map('trim', explode(',', (string) $job->skills)))),
                'status' => $job->status,
                'rate_type' => $job->rate_type,
                'agreed_rate' => $job->agreed_rate ? (float) $job->agreed_rate : null,

                'employer' => $job->employer ? [
                    'id' => $job->employer->id,
                    'company_name' => $job->employer->company_name,
                    'company_logo_url' => $this->fileUrl($job->employer->company_logo),
                ] : null,
                'freelancer' => $job->assignedFreelancer ? [
                    'id' => $job->assignedFreelancer->id,
                    'full_name' => $this->fullName($job->assignedFreelancer),
                    'profile_image_url' => $this->fileUrl($job->assignedFreelancer->profile_image),
                ] : null,

                'timeline' => [
                    'posted_at'    => optional($job->created_at)->format('Y-m-d'),
                    'assigned_at'  => optional($job->assigned_at)->format('Y-m-d'),
                    'started_at'   => optional($job->actual_start_date)->format('Y-m-d'),
                    'completed_at' => optional($job->completed_at)->format('Y-m-d'),
                    'deadline'     => optional($job->deadline)->format('Y-m-d'),
                    'duration_days' => ($job->actual_start_date && $job->completed_at)
                        ? $job->actual_start_date->diffInDays($job->completed_at) : null,
                    'on_time' => ($job->completed_at && $job->deadline)
                        ? $job->completed_at->startOfDay()->lte($job->deadline->startOfDay()) : null,
                ],

                'hours_logged' => $job->rate_type === 'hourly' ? round($hours, 2) : null,
                'earnings_so_far' => $job->rate_type === 'hourly' && $job->agreed_rate
                    ? round($hours * (float) $job->agreed_rate, 2) : null,

                'finances' => $payment ? [
                    'gross' => round((float) $payment->gross, 2),
                    'platform_fee' => round((float) $payment->platform_fee, 2),
                    'tax' => round((float) $payment->tax, 2),
                    'net_to_freelancer' => round((float) $payment->net, 2),
                    'currency' => $payment->currency ?? $currency,
                    'payment_status' => $payment->status,
                    'paid_at' => optional($payment->paid_at)->format('Y-m-d'),
                    'invoice_id' => $payment->invoice_id,
                ] : null,

                'review' => $job->review ? [
                    'stars' => (int) $job->review->stars,
                    'review_text' => $job->review->review_text,
                    'reviewed_at' => optional($job->review->reviewed_at)->format('Y-m-d'),
                ] : null,

                'flags' => $flags,
            ];
        })->values();

        return [
            'count' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total_pages' => $paginator->lastPage(),
            'items' => $items,
        ];
    }

    protected function alerts(): array
    {
        $alerts = [];

        $pendingEmployers = Employer::where('verification_status', 'inactive')->count();
        if ($pendingEmployers > 0) {
            $alerts[] = [
                'type' => 'pending_employer_verification',
                'count' => $pendingEmployers,
                'message' => "$pendingEmployers employer(s) awaiting admin approval.",
            ];
        }

        $overdue = Job::whereIn('status', ['in_progress', 'assigned'])
            ->whereDate('deadline', '<', now())->count();
        if ($overdue > 0) {
            $alerts[] = [
                'type' => 'overdue_in_progress_jobs',
                'count' => $overdue,
                'message' => "$overdue in-progress job(s) are past their deadline.",
            ];
        }

        $disputed = JobPayment::where('status', 'disputed')->count();
        if ($disputed > 0) {
            $alerts[] = [
                'type' => 'disputed_payments',
                'count' => $disputed,
                'message' => "$disputed job payment(s) currently disputed.",
            ];
        }

        $failedWithdrawals = FreelancerWithdrawal::where('status', 'failed')->count();
        if ($failedWithdrawals > 0) {
            $alerts[] = [
                'type' => 'failed_withdrawals',
                'count' => $failedWithdrawals,
                'message' => "$failedWithdrawals withdrawal(s) failed and need review.",
            ];
        }

        return $alerts;
    }

    protected function flagsFor(Job $job): array
    {
        $flags = [];
        if ($job->completed_at && $job->deadline && $job->completed_at->startOfDay()->gt($job->deadline->startOfDay())) {
            $flags[] = 'late_delivery';
        }
        if (in_array($job->status, ['in_progress', 'assigned'])
            && $job->deadline && $job->deadline->isPast()) {
            $flags[] = 'overdue';
        }
        if ($job->payments->contains('status', 'disputed')) {
            $flags[] = 'disputed_payment';
        }
        if ($job->review && $job->review->stars <= 2) {
            $flags[] = 'low_rating';
        }
        return $flags;
    }

    protected function median($values): float
    {
        $count = $values->count();
        if ($count === 0) { return 0.0; }
        if ($count % 2 === 1) {
            return (float) $values[(int) floor($count / 2)];
        }
        return ((float) $values[$count / 2 - 1] + (float) $values[$count / 2]) / 2;
    }

    protected function fullName(Freelancer $f): string
    {
        return trim($f->first_name . ' ' . ($f->other_names ?: '') . ' ' . $f->last_name);
    }

    protected function fileUrl(?string $path): ?string
    {
        if (!$path) { return null; }
        $relative = ltrim(preg_replace('#^/?storage/#', '', $path), '/');
        return asset('storage/' . $relative);
    }
}
