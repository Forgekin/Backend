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
        // User::factory(10)->create();

        $this->call([
            ShiftSeeder::class,
        ]);
        $this->call([RolesAndPermissionsSeeder::class]);

        $this->call([
            SuperAdminSeeder::class,
        ]);


    }
}

// RUn the below command to seed the database
// php artisan migrate --seed or the below command
// php artisan db:seed --class=ShiftSeeder

