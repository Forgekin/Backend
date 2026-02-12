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
        Schema::create('freelancers', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('other_names')->nullable();
            $table->string('profession')->nullable();
            $table->string('bio')->nullable();
            $table->string('location')->nullable();
            $table->decimal('hourly_rate', 10, 2)->nullable();
            $table->string('email')->unique();
            $table->string('contact', 15);
            $table->string('password');
            $table->enum('gender', ['male', 'female', 'other'])->default('other');
            $table->enum('proficiency', ['beginner', 'intermediate', 'advanced'])->default('beginner');
            $table->date('dob');
            $table->string('profile_image')->nullable();
            $table->timestamp('verification_code_expires_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('verification_code')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('freelancers');
    }
};
