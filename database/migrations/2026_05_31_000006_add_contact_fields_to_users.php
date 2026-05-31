<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * The original create_users_table never defined contact/dob/gender, but the
     * User model treats them as fillable and the profile-update endpoints write
     * `contact`. Add any that are missing so environments stay in sync.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'contact')) {
                $table->string('contact')->nullable();
            }
            if (! Schema::hasColumn('users', 'dob')) {
                $table->date('dob')->nullable();
            }
            if (! Schema::hasColumn('users', 'gender')) {
                $table->string('gender')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['contact', 'dob', 'gender'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
