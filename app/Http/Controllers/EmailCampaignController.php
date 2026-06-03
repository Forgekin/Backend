<?php

namespace App\Http\Controllers;

use App\Jobs\SendEmailCampaign;
use App\Models\EmailCampaign;
use App\Services\CampaignDispatcher;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Email broadcasting & newsletter management.
 *
 * Campaigns are created as drafts, scheduled, or sent immediately. Sending is
 * handled by {@see SendEmailCampaign}: dispatched synchronously by default
 * (works with no queue worker) and queue-ready for when a worker is available.
 */
class EmailCampaignController extends Controller
{
    /** Whether to push sends onto the queue (needs a worker) or run inline. */
    protected function useQueue(): bool
    {
        return (bool) env('CAMPAIGNS_QUEUE', false);
    }

    protected function dispatchSend(EmailCampaign $campaign): void
    {
        if ($this->useQueue()) {
            SendEmailCampaign::dispatch($campaign->id);
        } else {
            SendEmailCampaign::dispatchSync($campaign->id);
        }
    }

    /**
     * List campaigns (newest first).
     *
     * @group Email Campaigns
     * @queryParam per_page integer Items per page (default 12, max 50).
     * @queryParam status string Filter by status. Example: sent
     */
    public function index(Request $request)
    {
        $perPage = min((int) $request->query('per_page', 12), 50);

        $query = EmailCampaign::query()->with('creator')->latest();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json(['success' => true, 'data' => $query->paginate($perPage)]);
    }

    /**
     * Recipient counts per audience, for the composer.
     *
     * @group Email Campaigns
     * @response 200 {"success":true,"data":{"freelancers":12,"employers":4,"system_users":3,"everyone":18}}
     */
    public function audiences()
    {
        return response()->json(['success' => true, 'data' => EmailCampaign::audienceCounts()]);
    }

    /**
     * Show a single campaign.
     *
     * @group Email Campaigns
     * @urlParam id integer required
     */
    public function show(string $id)
    {
        $campaign = EmailCampaign::with('creator')->find($id);
        if (! $campaign) {
            return response()->json(['success' => false, 'message' => 'Campaign not found.'], 404);
        }
        return response()->json(['success' => true, 'data' => $campaign]);
    }

    /**
     * Create a campaign — save as draft, schedule, or send now.
     *
     * @group Email Campaigns
     * @bodyParam subject string required Example: June product update
     * @bodyParam body string required HTML body.
     * @bodyParam audience string required One of: freelancers, employers, system_users, everyone.
     * @bodyParam action string required One of: draft, schedule, send.
     * @bodyParam scheduled_at string Required when action=schedule (a future datetime).
     */
    public function store(Request $request)
    {
        $data = $this->validatePayload($request, true);

        $campaign = EmailCampaign::create([
            'created_by' => $request->user()?->id,
            'subject' => $data['subject'],
            'body' => $data['body'],
            'audience' => $data['audience'],
            'status' => 'draft',
            'scheduled_at' => null,
        ]);

        return $this->applyAction($campaign, $data);
    }

    /**
     * Update a campaign that hasn't gone out yet (draft / scheduled), and
     * optionally change its action (keep as draft, (re)schedule, or send now).
     *
     * @group Email Campaigns
     * @urlParam id integer required
     */
    public function update(Request $request, string $id)
    {
        $campaign = EmailCampaign::find($id);
        if (! $campaign) {
            return response()->json(['success' => false, 'message' => 'Campaign not found.'], 404);
        }

        if (! in_array($campaign->status, ['draft', 'scheduled'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only draft or scheduled campaigns can be edited.',
            ], 422);
        }

        $data = $this->validatePayload($request, false);

        $campaign->fill([
            'subject' => $data['subject'],
            'body' => $data['body'],
            'audience' => $data['audience'],
        ])->save();

        return $this->applyAction($campaign, $data);
    }

    /**
     * Send an existing draft / scheduled / failed campaign now.
     *
     * @group Email Campaigns
     * @urlParam id integer required
     */
    public function send(string $id)
    {
        $campaign = EmailCampaign::find($id);
        if (! $campaign) {
            return response()->json(['success' => false, 'message' => 'Campaign not found.'], 404);
        }

        if (in_array($campaign->status, ['sending', 'sent'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This campaign has already been sent or is sending.',
            ], 422);
        }

        $campaign->forceFill(['status' => 'queued', 'scheduled_at' => null])->save();
        $this->dispatchSend($campaign);

        return response()->json([
            'success' => true,
            'message' => $this->useQueue() ? 'Campaign queued for sending.' : 'Campaign sent.',
            'data' => $campaign->fresh(),
        ]);
    }

    /**
     * Process scheduled campaigns whose time has arrived. Provided so scheduled
     * sends work without a cron — an admin (or a simple cron curl) can trigger
     * it. Also wrapped by the `campaigns:run-due` console command.
     *
     * @group Email Campaigns
     * @response 200 {"success":true,"message":"Processed 2 due campaign(s).","data":{"processed":2}}
     */
    public function runDue()
    {
        $processed = CampaignDispatcher::runDue();

        return response()->json([
            'success' => true,
            'message' => "Processed {$processed} due campaign(s).",
            'data' => ['processed' => $processed],
        ]);
    }

    /**
     * Delete a campaign (not while it is actively sending).
     *
     * @group Email Campaigns
     * @urlParam id integer required
     */
    public function destroy(string $id)
    {
        $campaign = EmailCampaign::find($id);
        if (! $campaign) {
            return response()->json(['success' => false, 'message' => 'Campaign not found.'], 404);
        }

        if ($campaign->status === 'sending') {
            return response()->json([
                'success' => false,
                'message' => 'A campaign that is currently sending cannot be deleted.',
            ], 422);
        }

        $campaign->delete();

        return response()->json(['success' => true, 'message' => 'Campaign deleted.']);
    }

    // --- helpers -----------------------------------------------------------

    protected function validatePayload(Request $request, bool $creating): array
    {
        return $request->validate([
            'subject'      => ['required', 'string', 'max:200'],
            'body'         => ['required', 'string', 'max:100000'],
            'audience'     => ['required', Rule::in(EmailCampaign::AUDIENCES)],
            'action'       => ['required', Rule::in(['draft', 'schedule', 'send'])],
            'scheduled_at' => ['nullable', 'date', 'after:now', 'required_if:action,schedule'],
        ]);
    }

    /**
     * Apply the requested action (draft / schedule / send) to a saved campaign
     * and return the JSON response.
     */
    protected function applyAction(EmailCampaign $campaign, array $data)
    {
        $action = $data['action'];

        if ($action === 'send') {
            $campaign->forceFill(['status' => 'queued', 'scheduled_at' => null])->save();
            $this->dispatchSend($campaign);

            return response()->json([
                'success' => true,
                'message' => $this->useQueue() ? 'Campaign queued for sending.' : 'Campaign sent.',
                'data' => $campaign->fresh(),
            ], 201);
        }

        if ($action === 'schedule') {
            $campaign->forceFill([
                'status' => 'scheduled',
                'scheduled_at' => $data['scheduled_at'],
            ])->save();

            return response()->json([
                'success' => true,
                'message' => 'Campaign scheduled.',
                'data' => $campaign->fresh(),
            ], 201);
        }

        // draft
        $campaign->forceFill(['status' => 'draft', 'scheduled_at' => null])->save();

        return response()->json([
            'success' => true,
            'message' => 'Draft saved.',
            'data' => $campaign->fresh(),
        ], 201);
    }
}
