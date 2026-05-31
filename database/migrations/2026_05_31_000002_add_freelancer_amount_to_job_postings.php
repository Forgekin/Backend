<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            // Amount the platform pays the assigned freelancer (set at assignment time).
            $table->decimal('freelancer_amount', 12, 2)->nullable()->after('agreed_rate');
        });
    }

    public function down(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropColumn('freelancer_amount');
        });
    }
};
