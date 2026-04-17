<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('employers', function (Blueprint $table) {
            $table->string('company_logo')->nullable()->after('business_type');
            $table->string('industry')->nullable()->after('company_logo');
            $table->string('company_size')->nullable()->after('industry');
            $table->string('location')->nullable()->after('company_size');
            $table->string('website')->nullable()->after('location');
            $table->string('founded', 4)->nullable()->after('website');
            $table->text('about')->nullable()->after('founded');
            $table->json('specialties')->nullable()->after('about');
        });
    }

    public function down(): void
    {
        Schema::table('employers', function (Blueprint $table) {
            $table->dropColumn([
                'company_logo',
                'industry',
                'company_size',
                'location',
                'website',
                'founded',
                'about',
                'specialties',
            ]);
        });
    }
};
