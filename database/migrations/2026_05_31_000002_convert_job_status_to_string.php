<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Convert job_postings.status from a MySQL ENUM to a plain VARCHAR so new
     * workflow statuses (e.g. 'accepted') no longer require a schema change
     * each time one is added. Status values are still validated at the
     * application layer (JobController). SQLite already stores it as a string.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE job_postings MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'new'");
        }
    }

    /**
     * Restore the ENUM (matching the post-'rejected' set). Note: rolling back
     * will fail if any rows hold a status outside this list (e.g. 'accepted').
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE job_postings MODIFY COLUMN status ENUM('new','pending_approval','done','assigned','in_progress','on_hold','approved','rejected') NOT NULL DEFAULT 'new'");
        }
    }
};
