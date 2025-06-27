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
            $table->string('description');
            $table->enum('skills', ['Driving', 'Cleaning', 'Farm Laborer', 'Welding', 'Artisan-Ship', 'Carpentry', 'Masonry', 'Plumbing', 'Tilling', 'Auto Electrician'])->default('Driving');
            $table->date('deadline');
            $table->enum('shift_type', ['Morning', 'Afternoon', 'Night', 'Any Shift'])->default('Morning');
            $table->decimal('budget_min', 12, 2)->nullable();
            $table->decimal('budget_max', 12, 2)->nullable();
            $table->enum('status', ['open', 'closed', 'pending'])->default('open');
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
