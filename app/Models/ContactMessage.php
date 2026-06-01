<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactMessage extends Model
{
    protected $fillable = [
        'name',
        'email',
        'subject',
        'message',
        'email_sent',
        'ip_address',
    ];

    protected $casts = [
        'email_sent' => 'boolean',
    ];
}
