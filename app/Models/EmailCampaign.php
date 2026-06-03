<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * An email broadcast / newsletter campaign and the logic for resolving which
 * accounts a given audience maps to.
 */
class EmailCampaign extends Model
{
    public const AUDIENCES = ['freelancers', 'employers', 'system_users', 'everyone'];

    protected $fillable = [
        'created_by',
        'subject',
        'body',
        'audience',
        'filters',
        'status',
        'scheduled_at',
        'started_at',
        'completed_at',
        'total_recipients',
        'sent_count',
        'failed_count',
        'last_error',
    ];

    protected $casts = [
        'filters' => 'array',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'total_recipients' => 'integer',
        'sent_count' => 'integer',
        'failed_count' => 'integer',
    ];

    protected $appends = ['creator_name'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getCreatorNameAttribute(): ?string
    {
        $u = $this->creator;
        if (! $u) {
            return null;
        }
        return trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: $u->email;
    }

    /**
     * Resolve an audience key to a de-duplicated collection of recipients,
     * each shaped as ['email' => ..., 'name' => ...].
     */
    public static function recipientsFor(string $audience): Collection
    {
        $out = collect();

        $wantFreelancers = in_array($audience, ['freelancers', 'everyone'], true);
        $wantEmployers   = in_array($audience, ['employers', 'everyone'], true);
        $wantUsers       = in_array($audience, ['system_users', 'everyone'], true);

        if ($wantFreelancers) {
            Freelancer::whereNotNull('email')
                ->select('email', 'first_name', 'last_name')
                ->chunk(500, function ($rows) use ($out) {
                    foreach ($rows as $r) {
                        $out->push([
                            'email' => $r->email,
                            'name' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: $r->email,
                        ]);
                    }
                });
        }

        if ($wantEmployers) {
            Employer::whereNotNull('email')
                ->select('email', 'company_name', 'first_name', 'last_name')
                ->chunk(500, function ($rows) use ($out) {
                    foreach ($rows as $r) {
                        $name = $r->company_name
                            ?: (trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: $r->email);
                        $out->push(['email' => $r->email, 'name' => $name]);
                    }
                });
        }

        if ($wantUsers) {
            User::whereNotNull('email')
                ->select('email', 'first_name', 'last_name')
                ->chunk(500, function ($rows) use ($out) {
                    foreach ($rows as $r) {
                        $out->push([
                            'email' => $r->email,
                            'name' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: $r->email,
                        ]);
                    }
                });
        }

        // De-duplicate by email (matters for "everyone" and any overlap).
        return $out->unique('email')->values();
    }

    /**
     * Recipient counts per audience, for the composer's audience picker.
     */
    public static function audienceCounts(): array
    {
        $freelancers = Freelancer::whereNotNull('email')->count();
        $employers   = Employer::whereNotNull('email')->count();
        $users       = User::whereNotNull('email')->count();

        // Exact distinct count for "everyone" (small datasets — safe to merge).
        $everyone = collect()
            ->merge(Freelancer::whereNotNull('email')->pluck('email'))
            ->merge(Employer::whereNotNull('email')->pluck('email'))
            ->merge(User::whereNotNull('email')->pluck('email'))
            ->unique()
            ->count();

        return [
            'freelancers' => $freelancers,
            'employers' => $employers,
            'system_users' => $users,
            'everyone' => $everyone,
        ];
    }
}
