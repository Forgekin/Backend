<?php

namespace App\Http\Controllers;

use App\Models\Employer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Notification;
use App\Notifications\NewEmployerRegistered;
use App\Notifications\EmployerApproved;

class EmployerController extends Controller
{
    /**
     * List employers
     *
     * Returns a paginated list of employers. Supports search and filtering by verification status.
     *
     * @group Employer Profile
     * @unauthenticated
     *
     * @queryParam search string Search by first_name, last_name, company_name, email, or contact. Example: TechCorp
     * @queryParam verified string Filter by verification_status value. Example: active
     * @queryParam per_page integer Results per page (max 100). Example: 15
     *
     * @response 200 scenario="Success" {"data":[],"links":{},"meta":{}}
     */
    public function index(Request $request)
    {
        $query = Employer::query();

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%$search%")
                    ->orWhere('last_name', 'like', "%$search%")
                    ->orWhere('company_name', 'like', "%$search%")
                    ->orWhere('email', 'like', "%$search%")
                    ->orWhere('contact', 'like', "%$search%");
            });
        }

        if ($request->has('verified')) {
            $verified = $request->input('verified');
            $query->where('verification_status', $verified);
        }

        $perPage = min((int) $request->input('per_page', 15), 100);
        $employers = $query->latest()->paginate($perPage);

        return response()->json($employers);
    }

    /**
     * Register employer
     *
     * Creates a new employer account with `inactive` status. An admin must activate the account before the employer can log in. Sends a notification email to the admin.
     *
     * @group Employer Registration & Authentication
     * @unauthenticated
     *
     * @bodyParam first_name string Optional. Example: Jane
     * @bodyParam last_name string Optional. Example: Smith
     * @bodyParam company_name string required Unique company name. Example: TechCorp Inc
     * @bodyParam email string required Unique, valid email (DNS validated). Example: jane@gmail.com
     * @bodyParam contact string Optional phone (max 15). Example: 0551234567
     * @bodyParam password string required Min 8 chars. Example: Password1!
     * @bodyParam password_confirmation string required Must match password. Example: Password1!
     * @bodyParam business_type string required One of: Startup, SME, Corporation. Example: Startup
     *
     * @response 201 scenario="Created" {"message":"Company registered successfully. Your account will be reviewed and activated by ForgeKin.","employer":{},"success":true}
     * @response 409 scenario="Duplicate email" {"message":"A Company with this email already exists.","success":false}
     * @response 409 scenario="Duplicate company" {"message":"A Company with this company name already exists.","success":false}
     * @response 422 scenario="Validation error" {"message":"The company name field is required.","errors":{}}
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'company_name' => 'required|string|max:255',
            'email' => 'required|email:rfc,dns',
            'contact' => 'nullable|string|max:15',
            'password' => 'required|string|min:8|confirmed',
            'business_type' => 'required|in:Startup,SME,Corporation'
        ]);

        if (Employer::where('email', $validated['email'])->exists()) {
            return response()->json([
                'message' => 'A Company with this email already exists.',
                'success' => false
            ], 409);
        }

        if (Employer::where('company_name', $validated['company_name'])->exists()) {
            return response()->json([
                'message' => 'A Company with this company name already exists.',
                'success' => false
            ], 409);
        }

        $validated['password'] = Hash::make($validated['password']);
        $validated['verification_status'] = 'inactive';

        $employer = Employer::create($validated);

        Notification::route('mail', config('app.admin_email'))
            ->notify(new NewEmployerRegistered($employer));

        return response()->json([
            'message' => 'Company registered successfully. Your account will be reviewed and activated by ForgeKin.',
            'employer' => $employer,
            'success' => true
        ], 201);
    }

    /**
     * Employer login
     *
     * Authenticates an employer and returns a Sanctum bearer token. Account must have `active` verification status. Rate limited to 5 attempts per minute.
     *
     * @group Employer Registration & Authentication
     * @unauthenticated
     *
     * @bodyParam email string required The employer's email. Example: jane@gmail.com
     * @bodyParam password string required Min 8 characters. Example: Password1!
     *
     * @response 200 scenario="Success" {"message":"Login successful","token":"1|abc123...","data":{},"success":true}
     * @response 401 scenario="Bad credentials" {"message":"The provided credentials are incorrect","success":false}
     * @response 403 scenario="Inactive account" {"message":"Your account has not been verified yet. Please contact ForgeKin to activate your account.","requires_verification":true,"success":false}
     * @response 429 scenario="Rate limited" {"message":"Too Many Attempts."}
     */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email:rfc',
            'password' => 'required|string|min:8'
        ]);

        $email = trim($validated['email']);
        $password = trim($validated['password']);

        $employer = Employer::where('email', 'like', $email)->first();

        $errorResponse = [
            'message' => 'The provided credentials are incorrect',
            'success' => false
        ];

        if (!$employer) {
            return response()->json($errorResponse, 401);
        }

        if (!Hash::check($password, $employer->password)) {
            \Log::warning('Failed login attempt for employer email: ' . $email);
            return response()->json($errorResponse, 401);
        }

        if ($employer->verification_status !== 'active') {
            return response()->json([
                'message' => 'Your account has not been verified yet. Please contact ForgeKin to activate your account.',
                'requires_verification' => true,
                'success' => false
            ], 403);
        }

        $employer->tokens()->delete();

        $token = $employer->createToken(
            'employer_auth_' . now()->format('Ymd_His')
        )->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'data' => $employer,
            'success' => true
        ]);
    }

    /**
     * Get employer
     *
     * Returns a single employer's details.
     *
     * @group Employer Profile
     * @unauthenticated
     *
     * @urlParam employer integer required The employer ID. Example: 1
     *
     * @response 200 scenario="Success" {"success":true,"data":{"id":1,"company_name":"TechCorp"}}
     * @response 404 scenario="Not found" {"message":"No query results for model [App\\Models\\Employer] 999"}
     */
    public function show(Employer $employer)
    {
        return response()->json([
            'success' => true,
            'data' => $employer
        ]);
    }

    /**
     * Update employer
     *
     * Updates the authenticated employer's profile. Only the employer themselves can update.
     *
     * @group Employer Profile
     * @authenticated
     *
     * @urlParam employer integer required The employer ID. Example: 1
     *
     * @bodyParam first_name string Optional. Example: Jane
     * @bodyParam last_name string Optional. Example: Smith
     * @bodyParam company_name string Optional (must be unique). Example: NewCorp Ltd
     * @bodyParam contact string Optional (max 15). Example: 0559876543
     * @bodyParam business_type string Optional. One of: Startup, SME, Corporation. Example: SME
     *
     * @response 200 scenario="Updated" {"success":true,"message":"Employer updated successfully.","data":{}}
     * @response 403 scenario="Unauthorized" {"success":false,"message":"Unauthorized."}
     */
    public function update(Request $request, Employer $employer)
    {
        if (auth()->id() !== $employer->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.'
            ], 403);
        }

        $validated = $request->validate([
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'company_name' => 'sometimes|string|max:255|unique:employers,company_name,' . $employer->id,
            'contact' => 'sometimes|string|max:15',
            'business_type' => 'sometimes|in:Startup,SME,Corporation',
        ]);

        $employer->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Employer updated successfully.',
            'data' => $employer->fresh()
        ]);
    }

    /**
     * Delete employer
     *
     * Permanently deletes the employer account. Only the employer themselves can delete.
     *
     * @group Employer Profile
     * @authenticated
     *
     * @urlParam employer integer required The employer ID. Example: 1
     *
     * @response 200 scenario="Deleted" {"success":true,"message":"Employer deleted successfully."}
     * @response 403 scenario="Unauthorized" {"success":false,"message":"Unauthorized."}
     */
    public function destroy(Employer $employer)
    {
        if (auth()->id() !== $employer->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.'
            ], 403);
        }

        $employer->delete();

        return response()->json([
            'success' => true,
            'message' => 'Employer deleted successfully.'
        ]);
    }

    /**
     * Approve employer
     *
     * Verifies/approves an employer account by setting `verification_status` to `active`. Requires admin authentication with the `employers.verify` permission.
     *
     * @group Employer Admin
     * @authenticated
     *
     * @urlParam employer integer required The employer ID. Example: 1
     *
     * @response 200 scenario="Approved" {"success":true,"message":"Employer approved successfully.","data":{}}
     * @response 200 scenario="Already active" {"success":true,"message":"Employer is already active.","data":{}}
     * @response 403 scenario="Forbidden" {"message":"User does not have the right permissions."}
     * @response 404 scenario="Not found" {"message":"No query results for model [App\\Models\\Employer] 999"}
     */
    public function approve(Employer $employer)
    {
        if ($employer->verification_status === 'active') {
            return response()->json([
                'success' => true,
                'message' => 'Employer is already active.',
                'data' => $employer
            ]);
        }

        $employer->update(['verification_status' => 'active']);

        $employer->notify(new EmployerApproved($employer));

        return response()->json([
            'success' => true,
            'message' => 'Employer approved successfully.',
            'data' => $employer->fresh()
        ]);
    }

    /**
     * Revoke employer verification
     *
     * Deactivates an employer by setting `verification_status` to `inactive`. Requires admin authentication with the `employers.verify` permission.
     *
     * @group Employer Admin
     * @authenticated
     *
     * @urlParam employer integer required The employer ID. Example: 1
     *
     * @response 200 scenario="Revoked" {"success":true,"message":"Employer verification revoked.","data":{}}
     * @response 403 scenario="Forbidden" {"message":"User does not have the right permissions."}
     */
    public function revokeVerification(Employer $employer)
    {
        $employer->update(['verification_status' => 'inactive']);
        $employer->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Employer verification revoked.',
            'data' => $employer->fresh()
        ]);
    }

    /**
     * Employer logout
     *
     * Revokes the current access token.
     *
     * @group Employer Registration & Authentication
     * @authenticated
     *
     * @response 200 scenario="Success" {"message":"You have been logged out successfully.","success":true}
     * @response 401 scenario="Unauthenticated" {"message":"Unauthenticated."}
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'You have been logged out successfully.',
            'success' => true
        ]);
    }
}
