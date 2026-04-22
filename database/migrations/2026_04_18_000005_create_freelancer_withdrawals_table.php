<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('freelancer_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('GHS');
            $table->string('method');
            $table->string('destination');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->string('reference')->nullable()->unique();
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['freelancer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelancer_withdrawals');
    }
};
