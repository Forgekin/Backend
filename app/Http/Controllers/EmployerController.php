<?php

namespace App\Http\Controllers;

use App\Models\Employer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Notification;
use App\Notifications\NewEmployerRegistered;

class EmployerController extends Controller
{
    /**
     * Display a paginated list of employers with search
     */
    public function index(Request $request)
    {
        $query = Employer::query();

        // Search functionality
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

        // Filter by verification status
        if ($request->has('verified')) {
            $verified = $request->input('verified');
            $query->where('verification_status', $verified);
        }

        // Pagination with default 15 per page, capped at 100
        $perPage = min((int) $request->input('per_page', 15), 100);
        $employers = $query->latest()->paginate($perPage);

        return response()->json($employers);
    }

    // Register Employer
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

        // Check for existing email
        if (Employer::where('email', $validated['email'])->exists()) {
            return response()->json([
                'message' => 'A Company with this email already exists.',
                'success' => false
            ], 409);
        }

        // Check for existing company name
        if (Employer::where('company_name', $validated['company_name'])->exists()) {
            return response()->json([
                'message' => 'A Company with this company name already exists.',
                'success' => false
            ], 409);
        }

        // Hash password and set inactive status
        $validated['password'] = Hash::make($validated['password']);
        $validated['verification_status'] = 'inactive';

        $employer = Employer::create($validated);

        // Send notification to admin
        Notification::route('mail', config('app.admin_email'))
            ->notify(new NewEmployerRegistered($employer));

        return response()->json([
            'message' => 'Company registered successfully. Your account will be reviewed and activated by ForgeKin.',
            'employer' => $employer,
            'success' => true
        ], 201);
    }


    // Login Employer
    public function login(Request $request)
    {
        // Validate with strong rules and error messages
        $validated = $request->validate([
            'email' => 'required|email:rfc',
            'password' => 'required|string|min:8'
        ]);

        // Trim to avoid accidental whitespace
        $email = trim($validated['email']);
        $password = trim($validated['password']);

        // Find employer with case-insensitive email match
        $employer = Employer::where('email', 'like', $email)->first();

        // Generic error response to avoid email enumeration
        $errorResponse = [
            'message' => 'The provided credentials are incorrect',
            'success' => false
        ];

        if (!$employer) {
            return response()->json($errorResponse, 401);
        }

        // Check password securely
        if (!Hash::check($password, $employer->password)) {
            \Log::warning('Failed login attempt for employer email: ' . $email);
            return response()->json($errorResponse, 401);
        }

        // Check verification status
        if ($employer->verification_status !== 'active') {
            return response()->json([
                'message' => 'Your account has not been verified yet. Please contact ForgeKin to activate your account.',
                'requires_verification' => true,
                'success' => false
            ], 403);
        }

        // Optionally revoke existing tokens for security
        $employer->tokens()->delete();

        // Create a fresh token with timestamp label
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
     * Display the specified employer
     */
    public function show(Employer $employer)
    {
        return response()->json([
            'success' => true,
            'data' => $employer
        ]);
    }

    /**
     * Update employer profile
     */
    public function update(Request $request, Employer $employer)
    {
        // Authorization: only the employer themselves can update
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
     * Delete employer account
     */
    public function destroy(Employer $employer)
    {
        // Authorization: only the employer themselves can delete
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

    // Logout Employer
    public function logout(Request $request)
    {
        // Delete the current access token
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'You have been logged out successfully.',
            'success' => true
        ]);
    }

}
