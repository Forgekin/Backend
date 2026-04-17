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
            'jobs' => ['create','read','update','delete','approve','assign'],
            'users' => ['create','read','update','delete'],
            'employers' => ['read','verify'],
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
    }
}
