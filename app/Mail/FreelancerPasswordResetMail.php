<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class FreelancerPasswordResetMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $token;
    public $url;

    // public function __construct($token)
    // {
    //     $this->token = $token;
    //     $this->url = config('app.frontend_url').'/reset-password?token='.$token;
    // }

    public function __construct($resetUrl)
    {
        $this->url = $resetUrl; // This is fine
    }


    public function build()
    {
        return $this->subject('Password Reset Request')
            ->markdown('emails.freelancer_password_reset');
    }
}
