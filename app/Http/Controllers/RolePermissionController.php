<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Roles
    |--------------------------------------------------------------------------
    */

    public function roles()
    {
        return response()->json([
            'success' => true,
            'data' => Role::with('permissions')->get()
        ]);
    }

    public function createRole(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:roles,name'
        ]);

        $role = Role::create(['name' => $validated['name']]);

        return response()->json([
            'success' => true,
            'message' => 'Role created successfully.',
            'data' => $role
        ], 201);
    }

    public function updateRole(Request $request, $id)
    {
        $role = Role::findOrFail($id);

        if ($role->name === 'Super-Admin') {
            return response()->json([
                'success' => false,
                'message' => 'Super-Admin role cannot be modified.'
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|unique:roles,name,' . $id
        ]);

        $role->update(['name' => $validated['name']]);

        return response()->json([
            'success' => true,
            'message' => 'Role updated successfully.',
            'data' => $role
        ]);
    }

    public function deleteRole($id)
    {
        $role = Role::findOrFail($id);

        if ($role->name === 'Super-Admin') {
            return response()->json([
                'success' => false,
                'message' => 'Super-Admin role cannot be deleted.'
            ], 403);
        }

        $role->delete();

        return response()->json([
            'success' => true,
            'message' => 'Role deleted successfully.'
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Permissions
    |--------------------------------------------------------------------------
    */

    public function permissions()
    {
        return response()->json([
            'success' => true,
            'data' => Permission::all()
        ]);
    }

    public function createPermission(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:permissions,name'
        ]);

        $permission = Permission::create([
            'name' => $validated['name']
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Permission created successfully.',
            'data' => $permission
        ], 201);
    }

    /*
    |--------------------------------------------------------------------------
    | Role Permissions Sync
    |--------------------------------------------------------------------------
    */

    public function syncRolePermissions(Request $request, $id)
    {
        $role = Role::findOrFail($id);

        if ($role->name === 'Super-Admin') {
            return response()->json([
                'success' => false,
                'message' => 'Super-Admin permissions cannot be modified.'
            ], 403);
        }

        $validated = $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'exists:permissions,name'
        ]);

        $role->syncPermissions($validated['permissions']);

        return response()->json([
            'success' => true,
            'message' => 'Permissions synced successfully.',
            'data' => $role->load('permissions')
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Assign Roles to User
    |--------------------------------------------------------------------------
    */

    public function syncUserRoles(Request $request, $id)
    {
        $user = User::findOrFail($id);

        if ($user->hasRole('Super-Admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Super-Admin roles cannot be modified.'
            ], 403);
        }

        $validated = $request->validate([
            'roles' => 'required|array',
            'roles.*' => 'exists:roles,name'
        ]);

        $user->syncRoles($validated['roles']);

        return response()->json([
            'success' => true,
            'message' => 'User roles updated successfully.',
            'data' => $user->load('roles')
        ]);
    }
}
