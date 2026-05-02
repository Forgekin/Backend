<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class SuperAdminSeeder extends Seeder
{
    public function run()
    {
        // Clear cached permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Ensure Super-Admin role exists
        $role = Role::firstOrCreate(['name' => 'Super-Admin']);

        
        // Create Super Admin user (only if not exists)
        $user = User::firstOrCreate(
            ['email' => 'superadmin@example.com'], // change this
            [
                'first_name' => 'Super',
                'last_name' => 'Admin',
                'password' => Hash::make('password123'), // change in production
            ]
        );

        // Assign role if not already assigned
        if (!$user->hasRole('Super-Admin')) {
            $user->assignRole($role);
        }

        $this->command->info('Super Admin created or already exists.');
    }
}
// php artisan db:seed
// php artisan db:seed --class=SuperAdminSeeder
// php artisan db:seed --class=RolesAndPermissionsSeeder
