<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    
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

    /*
    |--------------------------------------------------------------------------
    | List Users
    |--------------------------------------------------------------------------
    */
    public function index()
    {
        $users = User::with('roles')->get();

        return response()->json([
            'success' => true,
            'data' => $users
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Show Single User
    |--------------------------------------------------------------------------
    */
    public function show($id)
    {
        $user = User::with('roles')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $user
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Assign / Sync Roles
    |--------------------------------------------------------------------------
    */
    public function syncRoles(Request $request, $id)
    {
        $user = User::findOrFail($id);

        // Prevent modifying Super-Admin roles
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

    /*
    |--------------------------------------------------------------------------
    | Optional: Create User
    |--------------------------------------------------------------------------
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
            'other_name' => $validated['other_name'],
            'email' => $validated['email'],
            'password' => bcrypt($validated['password']),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User created successfully.',
            'data' => $user
        ], 201);
    }

    /*
    |--------------------------------------------------------------------------
    | Optional: Delete User
    |--------------------------------------------------------------------------
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
