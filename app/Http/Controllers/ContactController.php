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
     * then emails it to the ForgeKin support inbox. The sender's email is set
     * as the Reply-To so the team can respond directly. Sent over SMTP using
     * the configured mailer — no dedicated Mailable class is used (the HTML
     * body is built inline). Rate limited to 5 requests per minute per IP.
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

        // 2. Attempt the notification email. A failure here is logged but does
        // not fail the request — the message is already safely stored.
        try {
            // Where contact submissions are delivered. Defaults to the platform's
            // own "from" address (the support inbox) but can be overridden with a
            // dedicated CONTACT_TO_ADDRESS in .env.
            $recipient = config('mail.contact_to')
                ?? config('mail.from.address');

            $name    = e($validated['name']);
            $email   = e($validated['email']);
            $subject = e($validated['subject']);
            $body    = nl2br(e($validated['message']));

            $html = <<<HTML
                <div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto;color:#1c1c1e;">
                    <div style="background:#1c1c1e;padding:24px 28px;border-radius:16px 16px 0 0;">
                        <h2 style="margin:0;color:#E9A319;font-size:20px;">New contact message</h2>
                        <p style="margin:4px 0 0;color:#ffffff;opacity:.7;font-size:13px;">via the ForgeKin Contact form</p>
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

            Mail::html($html, function ($mail) use ($recipient, $validated) {
                $mail->to($recipient)
                    ->replyTo($validated['email'], $validated['name'])
                    ->subject('[ForgeKin Contact] ' . $validated['subject']);
            });

            $record->update(['email_sent' => true]);
        } catch (\Exception $e) {
            // Email failed, but the submission is stored — the team can still
            // read it. Don't fail the user-facing request.
            Log::error('Contact form email error: ' . $e->getMessage());
        }

        return response()->json([
            'message' => "Your message has been sent. We'll get back to you soon.",
            'success' => true,
        ]);
    }
}
