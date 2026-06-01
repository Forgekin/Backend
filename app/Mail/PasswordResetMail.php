<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Password reset email for any account type (freelancer, employer, ...).
 * The body is account-agnostic — only the recipient's first name (for the
 * greeting) and the tokenized reset URL differ.
 */
class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $url,
        public ?string $firstName = null,
    ) {
    }

    public function build()
    {
        return $this->subject('Password Reset Request')
            ->view('emails.password_reset', [
                'url' => $this->url,
                'firstName' => $this->firstName,
            ]);
    }
}
