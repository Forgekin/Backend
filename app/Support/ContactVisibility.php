<?php

namespace App\Support;

use App\Models\User;

/**
 * Decides whether a viewer may see a profile's contact PII (email, phone, DOB).
 *
 * Allowed for:
 *   • the profile owner (the authenticated account IS this profile),
 *   • Super-Admins,
 *   • Admins,
 *   • users granted the directory permission (employers.read).
 * Public / unauthenticated / other accounts: not allowed.
 */
class ContactVisibility
{
    /**
     * @param  object|null  $viewer  the authenticated account (User|Freelancer|Employer) or null
     * @param  object|null  $owner   the profile model being viewed (for the owner check)
     */
    public static function allowed($viewer, $owner = null): bool
    {
        if (! $viewer) {
            return false;
        }

        // Profile owner — same account type AND same id.
        if ($owner !== null
            && get_class($viewer) === get_class($owner)
            && (int) $viewer->getKey() === (int) $owner->getKey()) {
            return true;
        }

        // Staff / permissioned admin users.
        if ($viewer instanceof User) {
            return $viewer->hasRole('Super-Admin')
                || $viewer->hasRole('Admin')
                || $viewer->can('employers.read');
        }

        return false;
    }
}
