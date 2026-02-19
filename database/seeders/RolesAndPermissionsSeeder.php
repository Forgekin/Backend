<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $modules = [
            'jobs' => [
                'create',
                'read',
                'update',
                'delete',
                'approve',
                'assign',
            ],
            'users' => [
                'create',
                'read',
                'update',
                'delete',
            ],
            'roles' => [
                'manage',
            ],
            'permissions' => [
                'manage',
            ],
        ];

        foreach ($modules as $module => $actions) {
            foreach ($actions as $action) {
                Permission::firstOrCreate([
                    'name' => "{$module}.{$action}"
                ]);
            }

            Permission::firstOrCreate([
                'name' => "{$module}.*"
            ]);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'Super-Admin']);
        $admin = Role::firstOrCreate(['name' => 'Admin']);
        $reception = Role::firstOrCreate(['name' => 'Reception']);

        $superAdmin->givePermissionTo(Permission::all());

        $admin->syncPermissions([
            'jobs.*',
            'users.read',
            'users.update',
        ]);

        $reception->syncPermissions([
            'jobs.create',
            'jobs.read',
        ]);
    }
}
