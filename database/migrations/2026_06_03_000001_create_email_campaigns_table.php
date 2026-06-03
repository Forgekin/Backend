<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Email broadcasting / newsletter campaigns. A single row is one campaign:
     * a subject + HTML body sent to a target audience, optionally scheduled.
     * `created_by` is a plain indexed column (no FK constraint — keeps the
     * migration resilient and avoids cross-table FK issues).
     */
    public function up(): void
    {
        Schema::create('email_campaigns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->string('subject', 200);
            $table->longText('body'); // admin-authored HTML
            // 'freelancers' | 'employers' | 'system_users' | 'everyone'
            $table->string('audience', 30)->default('everyone');
            $table->json('filters')->nullable(); // reserved for future targeting
            // draft | scheduled | queued | sending | sent | failed | canceled
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_campaigns');
    }
};
