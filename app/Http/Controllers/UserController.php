<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * Admin login
     *
     * Authenticates an admin user and returns a Sanctum bearer token. Rate limited to 5 attempts per minute.
     *
     * @group Admin Authentication
     * @unauthenticated
     *
     * @bodyParam email string required Admin email. Example: superadmin@example.com
     * @bodyParam password string required Admin password. Example: password123
     *
     * @response 200 scenario="Success" {"success":true,"message":"Login successful.","token":"1|abc123...","user":{"id":1,"first_name":"Super","last_name":"Admin","roles":[{"name":"Super-Admin"}]}}
     * @response 401 scenario="Bad credentials" {"success":false,"message":"Invalid credentials."}
     * @response 429 scenario="Rate limited" {"message":"Too Many Attempts."}
     */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.'
            ], 401);
        }

        $token = $user->createToken('admin-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'token' => $token,
            'user' => $user->load('roles')
        ]);
    }

    /**
     * List users
     *
     * Returns all admin users with their roles. Requires Super-Admin role.
     *
     * @group Admin User Management
     * @authenticated
     *
     * @response 200 scenario="Success" {"success":true,"data":[{"id":1,"first_name":"Super","last_name":"Admin","roles":[]}]}
     * @response 401 scenario="Unauthenticated" {"message":"Unauthenticated."}
     * @response 403 scenario="Forbidden" {"message":"User does not have the right roles."}
     */
    public function index()
    {
        $users = User::with('roles')->get();

        return response()->json([
            'success' => true,
            'data' => $users
        ]);
    }

    /**
     * Get user
     *
     * Returns a single admin user with their roles. Requires Super-Admin role.
     *
     * @group Admin User Management
     * @authenticated
     *
     * @urlParam id integer required The user ID. Example: 1
     *
     * @response 200 scenario="Success" {"success":true,"data":{"id":1,"first_name":"Super","roles":[]}}
     * @response 404 scenario="Not found" {"message":"No query results for model [App\\Models\\User] 999"}
     */
    public function show($id)
    {
        $user = User::with('roles')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $user
        ]);
    }

    /**
     * Sync user roles
     *
     * Replaces all roles on the user with the provided list. Cannot modify Super-Admin users. Requires Super-Admin role.
     *
     * @group Admin User Management
     * @authenticated
     *
     * @urlParam id integer required The user ID. Example: 2
     *
     * @bodyParam roles string[] required Array of role names. Example: ["Admin"]
     *
     * @response 200 scenario="Synced" {"success":true,"message":"User roles updated successfully.","data":{"id":2,"roles":[{"name":"Admin"}]}}
     * @response 403 scenario="Super-Admin protected" {"success":false,"message":"Super-Admin roles cannot be modified."}
     * @response 422 scenario="Invalid role" {"message":"The selected roles.0 is invalid.","errors":{}}
     */
    public function syncRoles(Request $request, $id)
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

    /**
     * Create user
     *
     * Creates a new admin user account. Requires Super-Admin role.
     *
     * @group Admin User Management
     * @authenticated
     *
     * @bodyParam first_name string required First name. Example: New
     * @bodyParam last_name string required Last name. Example: Admin
     * @bodyParam other_name string Optional middle name. Example: James
     * @bodyParam email string required Unique email. Example: newadmin@example.com
     * @bodyParam password string required Min 8 characters. Example: Password1!
     *
     * @response 201 scenario="Created" {"success":true,"message":"User created successfully.","data":{"id":2,"first_name":"New","email":"newadmin@example.com"}}
     * @response 422 scenario="Duplicate email" {"message":"The email has already been taken.","errors":{"email":["The email has already been taken."]}}
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'other_name' => 'nullable|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
        ]);

        $user = User::create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'other_names' => $validated['other_name'] ?? null,
            'email' => $validated['email'],
            'password' => bcrypt($validated['password']),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User created successfully.',
            'data' => $user
        ], 201);
    }

    /**
     * Delete user
     *
     * Permanently deletes an admin user. Cannot delete users with the Super-Admin role. Requires Super-Admin role.
     *
     * @group Admin User Management
     * @authenticated
     *
     * @urlParam id integer required The user ID. Example: 2
     *
     * @response 200 scenario="Deleted" {"success":true,"message":"User deleted successfully."}
     * @response 403 scenario="Super-Admin protected" {"success":false,"message":"Super-Admin cannot be deleted."}
     * @response 404 scenario="Not found" {"message":"No query results for model [App\\Models\\User] 999"}
     */
    public function destroy($id)
    {
        $user = User::findOrFail($id);

        if ($user->hasRole('Super-Admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Super-Admin cannot be deleted.'
            ], 403);
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully.'
        ]);
    }
}
