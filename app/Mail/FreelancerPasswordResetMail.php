<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class FreelancerPasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public $token;
    public $url;
    public $firstName;

    public function __construct($resetUrl, ?string $firstName = null)
    {
        $this->url = $resetUrl;
        $this->firstName = $firstName;
    }


    public function build()
    {
        return $this->subject('Password Reset Request')
            ->markdown('emails.freelancer_password_reset', [
                'firstName' => $this->firstName,
            ]);
    }
}
