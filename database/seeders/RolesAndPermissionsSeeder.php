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
            'campaigns' => ['manage'],
            'admin' => ['dashboard'],
            'roles' => ['manage'],
            'permissions' => ['manage'],
        ];

        // Everything is pinned to the 'web' guard — the guard admin users
        // resolve to — so role/permission/user assignment never mismatches.
        foreach ($modules as $module => $actions) {
            foreach ($actions as $action) {
                Permission::firstOrCreate(['name' => "{$module}.{$action}", 'guard_name' => 'web']);
            }
            Permission::firstOrCreate(['name' => "{$module}.*", 'guard_name' => 'web']);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'Super-Admin', 'guard_name' => 'web']);
        $superAdmin->givePermissionTo(Permission::all());

        // Admin: job & employer moderation (including reject) and the dashboard,
        // but NOT user/role/permission management (Super-Admin only).
        $admin = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $admin->givePermissionTo([
            'jobs.create', 'jobs.read', 'jobs.update', 'jobs.delete',
            'jobs.approve', 'jobs.reject', 'jobs.assign',
            'employers.read', 'employers.verify',
            'campaigns.manage',
            'admin.dashboard',
        ]);
    }
}
