<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('job_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('job_postings')->cascadeOnDelete();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->decimal('hours', 6, 2);
            $table->date('logged_for');
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['freelancer_id', 'logged_for']);
            $table->index(['job_id', 'logged_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_hours');
    }
};
