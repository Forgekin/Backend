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

    public function __construct($token)
    {
        $this->token = $token;
        $this->url = config('app.frontend_url').'/reset-password?token='.$token;
    }

    public function build()
    {
        return $this->subject('Password Reset Request')
                    ->markdown('emails.freelancer_password_reset');
    }
}
