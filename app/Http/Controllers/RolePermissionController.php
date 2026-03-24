<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionController extends Controller
{
    /**
     * List roles
     *
     * Returns all roles with their associated permissions. Requires Super-Admin role.
     *
     * @group Admin Roles & Permissions
     * @authenticated
     *
     * @response 200 scenario="Success" {"success":true,"data":[{"id":1,"name":"Super-Admin","permissions":[{"name":"jobs.create"}]}]}
     */
    public function roles()
    {
        return response()->json([
            'success' => true,
            'data' => Role::with('permissions')->get()
        ]);
    }

    /**
     * Create role
     *
     * Creates a new role. Name must be unique. Requires Super-Admin role.
     *
     * @group Admin Roles & Permissions
     * @authenticated
     *
     * @bodyParam name string required Unique role name. Example: Editor
     *
     * @response 201 scenario="Created" {"success":true,"message":"Role created successfully.","data":{"id":2,"name":"Editor"}}
     * @response 422 scenario="Duplicate" {"message":"The name has already been taken.","errors":{"name":["The name has already been taken."]}}
     */
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

    /**
     * Update role
     *
     * Renames an existing role. The Super-Admin role cannot be modified. Requires Super-Admin role.
     *
     * @group Admin Roles & Permissions
     * @authenticated
     *
     * @urlParam id integer required The role ID. Example: 2
     *
     * @bodyParam name string required New unique role name. Example: Manager
     *
     * @response 200 scenario="Updated" {"success":true,"message":"Role updated successfully.","data":{"id":2,"name":"Manager"}}
     * @response 403 scenario="Protected" {"success":false,"message":"Super-Admin role cannot be modified."}
     */
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

    /**
     * Delete role
     *
     * Permanently deletes a role. The Super-Admin role cannot be deleted. Requires Super-Admin role.
     *
     * @group Admin Roles & Permissions
     * @authenticated
     *
     * @urlParam id integer required The role ID. Example: 2
     *
     * @response 200 scenario="Deleted" {"success":true,"message":"Role deleted successfully."}
     * @response 403 scenario="Protected" {"success":false,"message":"Super-Admin role cannot be deleted."}
     */
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

    /**
     * List permissions
     *
     * Returns all available permissions. Requires Super-Admin role.
     *
     * @group Admin Roles & Permissions
     * @authenticated
     *
     * @response 200 scenario="Success" {"success":true,"data":[{"id":1,"name":"jobs.create"},{"id":2,"name":"jobs.read"}]}
     */
    public function permissions()
    {
        return response()->json([
            'success' => true,
            'data' => Permission::all()
        ]);
    }

    /**
     * Create permission
     *
     * Creates a new permission. Name must be unique. Requires Super-Admin role.
     *
     * @group Admin Roles & Permissions
     * @authenticated
     *
     * @bodyParam name string required Unique permission name (dot notation recommended). Example: reports.view
     *
     * @response 201 scenario="Created" {"success":true,"message":"Permission created successfully.","data":{"id":5,"name":"reports.view"}}
     * @response 422 scenario="Duplicate" {"message":"The name has already been taken.","errors":{"name":["The name has already been taken."]}}
     */
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

    /**
     * Sync role permissions
     *
     * Replaces all permissions on a role with the provided list. The Super-Admin role cannot be modified. Requires Super-Admin role.
     *
     * @group Admin Roles & Permissions
     * @authenticated
     *
     * @urlParam id integer required The role ID. Example: 2
     *
     * @bodyParam permissions string[] required Array of permission names. Example: ["jobs.read","jobs.create"]
     *
     * @response 200 scenario="Synced" {"success":true,"message":"Permissions synced successfully.","data":{"id":2,"name":"Editor","permissions":[{"name":"jobs.read"}]}}
     * @response 403 scenario="Protected" {"success":false,"message":"Super-Admin permissions cannot be modified."}
     * @response 422 scenario="Invalid permission" {"message":"The selected permissions.0 is invalid.","errors":{}}
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

    /**
     * Sync user roles
     *
     * Replaces all roles on a user with the provided list. Cannot modify Super-Admin users. Requires Super-Admin role.
     *
     * @group Admin Roles & Permissions
     * @authenticated
     *
     * @urlParam id integer required The user ID. Example: 2
     *
     * @bodyParam roles string[] required Array of role names. Example: ["Admin","Editor"]
     *
     * @response 200 scenario="Synced" {"success":true,"message":"User roles updated successfully.","data":{"id":2,"roles":[{"name":"Admin"}]}}
     * @response 403 scenario="Protected" {"success":false,"message":"Super-Admin roles cannot be modified."}
     * @response 422 scenario="Invalid role" {"message":"The selected roles.0 is invalid.","errors":{}}
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
