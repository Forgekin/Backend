<?php
namespace Database\Seeders;
use App\Models\User;
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
            'jobs' => ['create','read','update','delete','approve','reject','assign'],
            'users' => ['create','read','update','delete'],
            'employers' => ['read','verify'],
            'admin' => ['dashboard'],
            'roles' => ['manage'],
            'permissions' => ['manage'],
        ];

        foreach ($modules as $module => $actions) {
            foreach ($actions as $action) {
                Permission::firstOrCreate(['name' => "{$module}.{$action}"]);
            }
            Permission::firstOrCreate(['name' => "{$module}.*"]);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'Super-Admin']);
        $superAdmin->givePermissionTo(Permission::all());

        // Admin: job & employer moderation (including reject) and the dashboard,
        // but NOT user/role/permission management (Super-Admin only).
        $admin = Role::firstOrCreate(['name' => 'Admin']);
        $admin->givePermissionTo([
            'jobs.create', 'jobs.read', 'jobs.update', 'jobs.delete',
            'jobs.approve', 'jobs.reject', 'jobs.assign',
            'employers.read', 'employers.verify',
            'admin.dashboard',
        ]);
    }
}
