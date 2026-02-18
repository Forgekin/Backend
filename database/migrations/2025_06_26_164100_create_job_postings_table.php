<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('job_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employer_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('description');
            $table->text('skills');
            $table->enum('rate_type', ['hourly', 'fixed'])->default('hourly');
            $table->enum('experience_level', ['beginner', 'intermediate', 'advanced'])->default('beginner');
            $table->decimal('min_budget', 12, 2)->nullable();
            $table->decimal('max_budget', 12, 2)->nullable();
            $table->date('deadline');
            $table->string('estimated_duration');
            $table->enum('shift_type', ['Morning', 'Afternoon', 'Night', 'Any Shift'])->default('Morning');
            $table->enum('status', ['new', 'pending_approval', 'done', 'assigned', 'in_progress', 'on_hold', 'approved'])->default('new');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_postings');
    }
};
