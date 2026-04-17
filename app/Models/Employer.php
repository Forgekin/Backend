<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Spatie\Permission\Traits\HasPermissions;

class Employer extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'company_name',
        'contact',
        'email',
        'password',
        'business_type',
        'verification_status',
        'email_verified_at',
        'company_logo',
        'industry',
        'company_size',
        'location',
        'website',
        'founded',
        'about',
        'specialties',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'specialties' => 'array',
        'email_verified_at' => 'datetime',
    ];

    protected $appends = ['company_logo_url'];

    public function getCompanyLogoUrlAttribute(): ?string
    {
        if (!$this->company_logo) {
            return null;
        }
        $relative = ltrim(preg_replace('#^/?storage/#', '', $this->company_logo), '/');
        return asset('storage/' . $relative);
    }
}
