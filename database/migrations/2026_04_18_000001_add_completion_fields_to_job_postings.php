<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->timestamp('assigned_at')->nullable()->after('assigned_freelancer_id');
            $table->date('actual_start_date')->nullable()->after('assigned_at');
            $table->timestamp('completed_at')->nullable()->after('actual_start_date');
            $table->decimal('agreed_rate', 12, 2)->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropColumn(['assigned_at', 'actual_start_date', 'completed_at', 'agreed_rate']);
        });
    }
};
