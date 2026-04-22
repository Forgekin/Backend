<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('job_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('job_postings')->cascadeOnDelete();
            $table->foreignId('employer_id')->constrained('employers')->cascadeOnDelete();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->decimal('gross', 12, 2);
            $table->decimal('platform_fee', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('net', 12, 2);
            $table->string('currency', 3)->default('GHS');
            $table->enum('status', ['pending', 'paid', 'disputed', 'refunded'])->default('pending');
            $table->string('invoice_id')->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['freelancer_id', 'status']);
            $table->index(['freelancer_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_payments');
    }
};
