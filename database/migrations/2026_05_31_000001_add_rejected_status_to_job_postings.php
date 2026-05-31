<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable()->after('status');
        });

        if (DB::getDriverName() === 'mysql') {
            // Widen the ENUM in place to add 'rejected'.
            DB::statement("ALTER TABLE job_postings MODIFY COLUMN status ENUM('new','pending_approval','done','assigned','in_progress','on_hold','approved','rejected') NOT NULL DEFAULT 'new'");
        } else {
            // SQLite (and others) store an enum as a CHECK-constrained varchar.
            // Converting it to a plain string drops the constraint so the new
            // 'rejected' value is accepted. Status values are still validated
            // at the application layer.
            Schema::table('job_postings', function (Blueprint $table) {
                $table->string('status', 50)->default('new')->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropColumn('rejection_reason');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE job_postings MODIFY COLUMN status ENUM('new','pending_approval','done','assigned','in_progress','on_hold','approved') NOT NULL DEFAULT 'new'");
        }
        // On SQLite the column simply remains a string — harmless.
    }
};
