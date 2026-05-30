<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

class StorageUrl
{
    /**
     * Build a public URL for a file stored on the "public" disk.
     *
     * Accepts clean relative paths ("profile_images/x.jpg") as well as legacy
     * values that already carry a leading "/storage/" or "public/storage/"
     * prefix, and resolves them against the public disk's configured URL
     * (see config/filesystems.php → disks.public.url). Building from config
     * rather than asset()/the request host keeps the result stable regardless
     * of how the file was reached.
     */
    public static function make(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        $relative = ltrim(preg_replace('#^/?(public/)?storage/#', '', $path), '/');

        return Storage::disk('public')->url($relative);
    }
}
