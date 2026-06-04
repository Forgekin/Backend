<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Add 'accepted' to the job status ENUM.
     *
     * The application (status validation + the assigned-freelancer workflow)
     * has always allowed a job to move from 'assigned' to 'accepted', but the
     * MySQL ENUM was never widened to include it. Writing 'accepted' therefore
     * failed with "Data truncated for column 'status'", surfacing as a 500 on
     * every accept action. SQLite stores the column as a plain string (the
     * 'rejected' migration dropped its CHECK constraint), so this only bit
     * production MySQL — which is why the test suite never caught it.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE job_postings MODIFY COLUMN status ENUM('new','pending_approval','done','assigned','accepted','in_progress','on_hold','approved','rejected') NOT NULL DEFAULT 'new'");
        }
        // SQLite (and others) already store status as an unconstrained string,
        // so 'accepted' is accepted without a schema change.
    }
    

    /**
     * Reverse the migration.
     *
     * Drops 'accepted' from the ENUM again. Any rows still set to 'accepted'
     * would be truncated by MySQL, so roll back only when none remain.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE job_postings MODIFY COLUMN status ENUM('new','pending_approval','done','assigned','in_progress','on_hold','approved','rejected') NOT NULL DEFAULT 'new'");
        }
    }
};
