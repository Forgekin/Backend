<?php

namespace App\Http\Controllers;

use App\Models\ContactMessage;
use App\Models\Employer;
use App\Models\Freelancer;
use App\Models\User;
use App\Notifications\SupportReplyReceived;
use App\Notifications\SupportRequestSubmitted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

class ContactController extends Controller
{
    /**
     * List contact-form submissions (admin).
     *
     * Returns the stored "Contact Us" submissions, newest first, for the admin
     * Support & Notification Center. Read-only — submissions are the source of
     * truth captured by {@see send()}.
     *
     * @group Contact
     *
     * @queryParam per_page integer Items per page (default 10, max 50). Example: 10
     *
     * @response 200 scenario="Success" {"success":true,"data":{"data":[],"current_page":1,"total":0}}
     */
    public function index(Request $request)
    {
        $perPage = min((int) $request->query('per_page', 10), 50);

        $messages = ContactMessage::query()
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $messages,
        ]);
    }

    /**
     * Show a single contact submission (admin).
     *
     * Returns the full message for the Support & Notification Center detail
     * view. If a `read_at` column exists on the table, the message is marked
     * read on open (forward-compatible — no error if the column is absent).
     *
     * @group Contact
     * @urlParam id integer required The submission id. Example: 12
     *
     * @response 200 scenario="Success" {"success":true,"data":{"id":12,"name":"Kofi","email":"kofi@example.com","subject":"Hi","message":"…"}}
     * @response 404 scenario="Not found" {"success":false,"message":"Message not found."}
     */
    public function show(string $id)
    {
        $message = ContactMessage::find($id);

        if (! $message) {
            return response()->json(['success' => false, 'message' => 'Message not found.'], 404);
        }

        if (Schema::hasColumn('contact_messages', 'read_at') && is_null($message->read_at)) {
            $message->forceFill(['read_at' => now()])->save();
        }

        return response()->json(['success' => true, 'data' => $message]);
    }

    /**
     * Reply to a contact submission (admin).
     *
     * Emails the admin's response to the original sender, branded and with the
     * sender's message quoted for context. The support inbox is set as Reply-To
     * so any further back-and-forth threads to the team. If a `replied_at`
     * column exists it is stamped (forward-compatible — optional schema).
     *
     * @group Contact
     * @urlParam id integer required The submission id. Example: 12
     * @bodyParam message string required The reply body (min 5 chars). Example: Thanks for reaching out — here's how to…
     *
     * @response 200 scenario="Sent" {"success":true,"message":"Your reply has been sent to kofi@example.com.","data":{}}
     * @response 404 scenario="Not found" {"success":false,"message":"Message not found."}
     * @response 500 scenario="Send failed" {"success":false,"message":"Failed to send the reply. Please try again."}
     */
    public function reply(Request $request, string $id)
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'min:5', 'max:5000'],
        ]);

        $contact = ContactMessage::find($id);

        if (! $contact) {
            return response()->json(['success' => false, 'message' => 'Message not found.'], 404);
        }

        // Where replies thread back to — the support inbox.
        $supportAddress = config('mail.contact_to') ?? config('mail.from.address');

        $firstName  = e(trim(explode(' ', (string) $contact->name)[0]) ?: 'there');
        $subjectRaw = $contact->subject ?: 'Your ForgeKin enquiry';
        $subject    = str_starts_with(strtolower($subjectRaw), 're:') ? $subjectRaw : 'Re: ' . $subjectRaw;
        $replyHtml  = nl2br(e($validated['message']));
        $origSubj   = e($subjectRaw);
        $origBody   = nl2br(e($contact->message));
        $logoPath   = public_path('email/forgekin-logo.png');

        try {
            Mail::send([], [], function ($mail) use ($contact, $supportAddress, $logoPath, $firstName, $subject, $replyHtml, $origSubj, $origBody, $validated) {
                $logo = $mail->embed($logoPath);

                $html = <<<HTML
                    <div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto;color:#1c1c1e;">
                        <div style="background:#1c1c1e;padding:28px;border-radius:16px 16px 0 0;text-align:center;">
                            <img src="{$logo}" alt="ForgeKin" width="170" style="display:inline-block;height:auto;border:0;" />
                        </div>
                        <div style="border:1px solid #eee;border-top:none;padding:32px 28px;border-radius:0 0 16px 16px;">
                            <h2 style="margin:0 0 14px;font-size:20px;">Hi {$firstName},</h2>
                            <p style="margin:0 0 18px;font-size:15px;line-height:1.7;color:#333;">{$replyHtml}</p>
                            <div style="background:#f7f7f8;border-left:3px solid #E9A319;border-radius:8px;padding:14px 18px;margin:22px 0 0;">
                                <p style="margin:0 0 6px;font-size:11px;color:#999;text-transform:uppercase;letter-spacing:.05em;font-weight:bold;">Your original message</p>
                                <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#555;">{$origSubj}</p>
                                <p style="margin:0;font-size:13px;line-height:1.6;color:#777;">{$origBody}</p>
                            </div>
                            <hr style="border:none;border-top:1px solid #eee;margin:24px 0 18px;">
                            <p style="margin:0;font-size:12px;color:#aaa;">Reply to this email to continue the conversation with our team.</p>
                        </div>
                    </div>
                HTML;

                $text = "Hi {$contact->name},\n\n"
                    . $validated['message'] . "\n\n"
                    . "-------------------------------\n"
                    . "Your original message\n"
                    . "Subject: {$contact->subject}\n"
                    . "{$contact->message}\n\n"
                    . "Reply to this email to continue the conversation with our team.";

                $mail->to($contact->email, $contact->name)
                    ->replyTo($supportAddress, 'ForgeKin Support')
                    ->subject($subject)
                    ->text($text)
                    ->html($html);
            });
        } catch (\Exception $e) {
            Log::error('Contact reply email error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to send the reply. Please try again.',
            ], 500);
        }

        if (Schema::hasColumn('contact_messages', 'replied_at')) {
            $contact->forceFill(['replied_at' => now()])->save();
        }

        // Mirror the reply into the recipient's in-app notification center if
        // they have a ForgeKin account (freelancer, employer or system user).
        $this->notifyRecipientInApp($contact->email, $subject, $validated['message']);

        return response()->json([
            'success' => true,
            'message' => 'Your reply has been sent to ' . $contact->email . '.',
            'data' => $contact,
        ]);
    }

    /**
     * Mirror a support reply into the recipient's in-app notification center.
     *
     * Freelancers, employers and system users are each independently notifiable,
     * so we match the destination email across all three and notify whichever
     * account(s) exist. The email is already sent by the caller — this is the
     * database-only in-app copy, and any failure here is non-fatal.
     */
    private function notifyRecipientInApp(?string $email, string $subject, string $body): void
    {
        if (! $email) {
            return;
        }

        try {
            $recipients = array_filter([
                Employer::where('email', $email)->first(),
                Freelancer::where('email', $email)->first(),
                User::where('email', $email)->first(),
            ]);

            foreach ($recipients as $recipient) {
                $recipient->notify(new SupportReplyReceived($subject, $body));
            }
        } catch (\Throwable $e) {
            Log::warning('Support reply in-app notification failed: ' . $e->getMessage());
        }
    }

    /**
     * Reply to a support-request notification (admin).
     *
     * Support requests from system users live as notifications (not as stored
     * contact rows), so the Support & Notification Center replies to them by
     * emailing the original sender directly. Restricted to support staff and
     * throttled at the route.
     *
     * @group Support
     *
     * @bodyParam email string required The sender's email. Example: kofi@example.com
     * @bodyParam name string The sender's name. Example: Kofi Mensah
     * @bodyParam subject string The original subject. Example: Can't approve a job
     * @bodyParam message string required The reply body (min 5 chars). Example: Try clearing your cache and…
     *
     * @response 200 scenario="Sent" {"success":true,"message":"Your reply has been sent to kofi@example.com."}
     * @response 500 scenario="Send failed" {"success":false,"message":"Failed to send the reply. Please try again."}
     */
    public function supportReply(Request $request)
    {
        $validated = $request->validate([
            'email'   => ['required', 'email', 'max:150'],
            'name'    => ['nullable', 'string', 'max:150'],
            'subject' => ['nullable', 'string', 'max:200'],
            'message' => ['required', 'string', 'min:5', 'max:5000'],
        ]);

        $supportAddress = config('mail.contact_to') ?? config('mail.from.address');

        $name       = $validated['name'] ?? null;
        $firstName  = e(trim(explode(' ', (string) ($name ?? ''))[0]) ?: 'there');
        $subjectRaw = $validated['subject'] ?: 'Your support request';
        $subject    = str_starts_with(strtolower($subjectRaw), 're:') ? $subjectRaw : 'Re: ' . $subjectRaw;
        $replyHtml  = nl2br(e($validated['message']));
        $logoPath   = public_path('email/forgekin-logo.png');

        try {
            Mail::send([], [], function ($mail) use ($validated, $supportAddress, $logoPath, $firstName, $subject, $replyHtml, $name) {
                $logo = $mail->embed($logoPath);

                $html = <<<HTML
                    <div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto;color:#1c1c1e;">
                        <div style="background:#1c1c1e;padding:28px;border-radius:16px 16px 0 0;text-align:center;">
                            <img src="{$logo}" alt="ForgeKin" width="170" style="display:inline-block;height:auto;border:0;" />
                        </div>
                        <div style="border:1px solid #eee;border-top:none;padding:32px 28px;border-radius:0 0 16px 16px;">
                            <h2 style="margin:0 0 14px;font-size:20px;">Hi {$firstName},</h2>
                            <p style="margin:0 0 18px;font-size:15px;line-height:1.7;color:#333;">{$replyHtml}</p>
                            <hr style="border:none;border-top:1px solid #eee;margin:24px 0 18px;">
                            <p style="margin:0;font-size:12px;color:#aaa;">Reply to this email to continue the conversation with our team.</p>
                        </div>
                    </div>
                HTML;

                $text = 'Hi ' . ($name ?: 'there') . ",\n\n"
                    . $validated['message'] . "\n\n"
                    . 'Reply to this email to continue the conversation with our team.';

                $mail->to($validated['email'], $name)
                    ->replyTo($supportAddress, 'ForgeKin Support')
                    ->subject($subject)
                    ->text($text)
                    ->html($html);
            });
        } catch (\Exception $e) {
            Log::error('Support reply email error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to send the reply. Please try again.',
            ], 500);
        }

        // Mirror the reply into the recipient's in-app notification center if
        // they have a ForgeKin account (freelancer, employer or system user).
        $this->notifyRecipientInApp($validated['email'], $subject, $validated['message']);

        return response()->json([
            'success' => true,
            'message' => 'Your reply has been sent to ' . $validated['email'] . '.',
        ]);
    }

    /**
     * Submit an internal support request (authenticated system user).
     *
     * Lets any signed-in user of the admin panel reach the support team. The
     * request is fanned out to every Super-Admin / Admin as a database
     * notification (so it appears in their Notifications tab) and an email,
     * with Reply-To set to the sender so staff can respond directly.
     *
     * @group Support
     *
     * @bodyParam subject string required The subject. Example: Can't approve a job
     * @bodyParam message string required The request body (min 5 chars). Example: When I approve job #9 I get an error…
     *
     * @response 200 scenario="Sent" {"success":true,"message":"Your message has been sent to the support team."}
     * @response 503 scenario="No staff" {"success":false,"message":"No support staff are available to receive your message right now."}
     */
    public function support(Request $request)
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'min:5', 'max:5000'],
        ]);

        $user = $request->user();
        $senderName = trim((($user->first_name ?? '') . ' ' . ($user->last_name ?? '')))
            ?: ($user->email ?? 'A system user');

        $admins = User::whereHas('roles', function ($q) {
            $q->whereIn('name', ['Super-Admin', 'Admin']);
        })->get();

        if ($admins->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No support staff are available to receive your message right now.',
            ], 503);
        }

        try {
            Notification::send($admins, new SupportRequestSubmitted(
                $validated['subject'],
                $validated['message'],
                $senderName,
                $user->email ?? null,
                $user->id ?? null,
            ));
        } catch (\Throwable $e) {
            Log::error('Support request notify error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to send your message. Please try again.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Your message has been sent to the support team.',
        ]);
    }

    /**
     * Send a contact message
     *
     * Receives a message from the public "Contact Us" form, stores it in the
     * `contact_messages` table (so nothing is lost if email delivery fails),
     * then emails it to the ForgeKin support inbox and sends a confirmation
     * copy to the person who submitted the form. The sender's email is set as
     * the Reply-To on the team notification so the team can respond directly.
     * Sent over SMTP using the configured mailer — no dedicated Mailable class
     * is used (the HTML body is built inline). Rate limited to 5 requests per
     * minute per IP.
     *
     * @group Contact
     * @unauthenticated
     *
     * @bodyParam name string required The sender's full name. Example: Kofi Mensah
     * @bodyParam email string required The sender's email (used as Reply-To). Example: kofi@example.com
     * @bodyParam subject string required The subject of the message. Example: General inquiry
     * @bodyParam message string required The message body (min 20 characters). Example: I would like to know more about hiring on ForgeKin.
     *
     * @response 200 scenario="Sent" {"message":"Your message has been sent. We'll get back to you soon.","success":true}
     * @response 422 scenario="Validation error" {"message":"The email field must be a valid email address.","errors":{"email":["The email field must be a valid email address."]}}
     * @response 429 scenario="Rate limited" {"message":"Too Many Attempts."}
     * @response 500 scenario="Server error" {"message":"Failed to send your message. Please try again later.","success":false}
     */
    public function send(Request $request)
    {
        $validated = $request->validate([
            'name'    => ['required', 'string', 'max:100'],
            'email'   => ['required', 'email', 'max:150'],
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'min:20', 'max:5000'],
        ]);

        // 1. Persist first — this is the source of truth. Even if the email
        // fails to send, the submission is never lost.
        try {
            $record = ContactMessage::create([
                'name'       => $validated['name'],
                'email'      => $validated['email'],
                'subject'    => $validated['subject'],
                'message'    => $validated['message'],
                'ip_address' => $request->ip(),
                'email_sent' => false,
            ]);
        } catch (\Exception $e) {
            Log::error('Contact form save error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to send your message. Please try again later.',
                'success' => false,
            ], 500);
        }

        // Pre-compute shared, escaped values used by both emails.
        // Where contact submissions are delivered. Defaults to the platform's
        // own "from" address (the support inbox) but can be overridden with a
        // dedicated CONTACT_TO_ADDRESS in .env.
        $recipient = config('mail.contact_to')
            ?? config('mail.from.address');

        $name      = e($validated['name']);
        $email     = e($validated['email']);
        $subject   = e($validated['subject']);
        $body      = nl2br(e($validated['message']));
        $firstName = e(trim(explode(' ', $validated['name'])[0]));

        // The ForgeKin logo is embedded inline (CID) into every email so it
        // renders reliably across clients without depending on a public URL.
        $logoPath = public_path('email/forgekin-logo.png');

        // 2. Attempt the notification email. A failure here is logged but does
        // not fail the request — the message is already safely stored.
        try {
            Mail::send([], [], function ($mail) use ($recipient, $validated, $logoPath, $name, $email, $subject, $body) {
                $logo = $mail->embed($logoPath);

                $html = <<<HTML
                    <div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto;color:#1c1c1e;">
                        <div style="background:#1c1c1e;padding:24px 28px;border-radius:16px 16px 0 0;">
                            <img src="{$logo}" alt="ForgeKin" width="150" style="display:block;height:auto;border:0;margin-bottom:10px;" />
                            <p style="margin:0;color:#E9A319;font-size:15px;font-weight:bold;">New contact message</p>
                            <p style="margin:2px 0 0;color:#ffffff;opacity:.7;font-size:13px;">via the ForgeKin Contact form</p>
                        </div>
                        <div style="border:1px solid #eee;border-top:none;padding:28px;border-radius:0 0 16px 16px;">
                            <p style="margin:0 0 6px;font-size:13px;color:#888;text-transform:uppercase;letter-spacing:.05em;">From</p>
                            <p style="margin:0 0 18px;font-size:16px;font-weight:600;">{$name} &lt;{$email}&gt;</p>

                            <p style="margin:0 0 6px;font-size:13px;color:#888;text-transform:uppercase;letter-spacing:.05em;">Subject</p>
                            <p style="margin:0 0 18px;font-size:16px;font-weight:600;">{$subject}</p>

                            <p style="margin:0 0 6px;font-size:13px;color:#888;text-transform:uppercase;letter-spacing:.05em;">Message</p>
                            <p style="margin:0;font-size:15px;line-height:1.6;">{$body}</p>

                            <hr style="border:none;border-top:1px solid #eee;margin:24px 0;">
                            <p style="margin:0;font-size:13px;color:#aaa;">Reply directly to this email to respond to {$name}.</p>
                        </div>
                    </div>
                HTML;

                $text = "New contact message via the ForgeKin Contact form\n\n"
                    . "From: {$validated['name']} <{$validated['email']}>\n"
                    . "Subject: {$validated['subject']}\n\n"
                    . "{$validated['message']}\n\n"
                    . "Reply directly to this email to respond to {$validated['name']}.";

                $mail->to($recipient)
                    ->replyTo($validated['email'], $validated['name'])
                    ->subject('[ForgeKin Contact] ' . $validated['subject'])
                    ->text($text)
                    ->html($html);
            });

            $record->update(['email_sent' => true]);
        } catch (\Exception $e) {
            // Email failed, but the submission is stored — the team can still
            // read it. Don't fail the user-facing request.
            Log::error('Contact form email error: ' . $e->getMessage());
        }

        // 3. Send a confirmation copy to the sender. Wrapped separately so a
        // failure here never affects the team-notification status above.
        try {
            Mail::send([], [], function ($mail) use ($validated, $logoPath, $recipient, $firstName, $subject, $body) {
                $logo = $mail->embed($logoPath);

                $ackHtml = <<<HTML
                    <div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto;color:#1c1c1e;">
                        <div style="background:#1c1c1e;padding:28px;border-radius:16px 16px 0 0;text-align:center;">
                            <img src="{$logo}" alt="ForgeKin" width="170" style="display:inline-block;height:auto;border:0;" />
                        </div>
                        <div style="border:1px solid #eee;border-top:none;padding:32px 28px;border-radius:0 0 16px 16px;">
                            <h2 style="margin:0 0 14px;font-size:20px;">Thanks for reaching out, {$firstName}!</h2>
                            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#444;">
                                We've received your message and a member of our team will get back to you
                                within one to two business days. Here's a copy of what you sent us:
                            </p>
                            <div style="background:#fffaf0;border:1px solid #f3e6c4;border-radius:12px;padding:18px 20px;margin:0 0 20px;">
                                <p style="margin:0 0 6px;font-size:12px;color:#a07614;text-transform:uppercase;letter-spacing:.05em;font-weight:bold;">Subject</p>
                                <p style="margin:0 0 14px;font-size:15px;font-weight:600;">{$subject}</p>
                                <p style="margin:0 0 6px;font-size:12px;color:#a07614;text-transform:uppercase;letter-spacing:.05em;font-weight:bold;">Your message</p>
                                <p style="margin:0;font-size:15px;line-height:1.6;color:#333;">{$body}</p>
                            </div>
                            <p style="margin:0 0 24px;font-size:14px;color:#666;">
                                If your enquiry is urgent, you can call us on
                                <a href="tel:+233555258911" style="color:#E9A319;text-decoration:none;font-weight:600;">0555 258 911</a>.
                            </p>
                            <hr style="border:none;border-top:1px solid #eee;margin:0 0 18px;">
                            <p style="margin:0;font-size:12px;color:#aaa;">
                                Need anything else? Just reply to this email or reach us at {$recipient}.
                            </p>
                        </div>
                    </div>
                HTML;

                $ackText = "Hi {$validated['name']},\n\n"
                    . "Thanks for reaching out to ForgeKin. We've received your message and a member "
                    . "of our team will get back to you within one to two business days.\n\n"
                    . "Here's a copy of what you sent us:\n"
                    . "Subject: {$validated['subject']}\n"
                    . "{$validated['message']}\n\n"
                    . "If your enquiry is urgent, call us on 0555 258 911.\n\n"
                    . "Need anything else? Just reply to this email or reach us at {$recipient}.\n\n"
                    . "— The ForgeKin Team";

                $mail->to($validated['email'], $validated['name'])
                    ->replyTo($recipient, 'ForgeKin')
                    ->subject('We received your message — ForgeKin')
                    ->text($ackText)
                    ->html($ackHtml);
            });
        } catch (\Exception $e) {
            Log::error('Contact form acknowledgement email error: ' . $e->getMessage());
        }

        return response()->json([
            'message' => "Your message has been sent. We'll get back to you soon.",
            'success' => true,
        ]);
    }
}
