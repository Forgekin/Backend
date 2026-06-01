<?php

namespace App\Http\Controllers;

use App\Models\ContactMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
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
