<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            ShiftSeeder::class,
            SuperAdminSeeder::class,
            FreelancerSeeder::class,
        ]);
    }
}

// RUn the below command to seed the database
// php artisan migrate --seed or the below command
// php artisan db:seed --class=ShiftSeeder

