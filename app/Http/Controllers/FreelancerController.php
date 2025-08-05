<?php

namespace App\Http\Controllers;

use App\Models\Freelancer;
use App\Http\Resources\FreelancerResource;
use App\Http\Requests\StoreFreelancerRequest;
use App\Http\Requests\UpdateFreelancerRequest;
use App\Mail\VerificationCodeMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;

class FreelancerController extends Controller
{
    /**
     * Display a paginated list of freelancers with search
     */
    public function index(Request $request)
    {
        $query = Freelancer::query();

        // Search functionality
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('fullname', 'like', "%$search%")
                    ->orWhere('email', 'like', "%$search%")
                    ->orWhere('contact', 'like', "%$search%");
            });
        }

        // Filter by verification status
        if ($request->has('verified')) {
            $query->whereNotNull('email_verified_at');
        }

        // Pagination with default 15 per page
        $perPage = $request->input('per_page', 15);
        $freelancers = $query->latest()->paginate($perPage);

        return FreelancerResource::collection($freelancers);
    }

    /**
     * Register a new freelancer with email verification
     */
    public function store(StoreFreelancerRequest $request)
    {
        $validated = $request->validated();

        $freelancer = Freelancer::create([
            'fullname' => $validated['fullname'],
            'email' => $validated['email'],
            'contact' => $validated['contact'],
            'password' => Hash::make($validated['password']),
            'gender' => $validated['gender'],
            'dob' => $validated['dob'],
            'verification_code' => Str::random(6),
            'verification_code_expires_at' => Carbon::now()->addMinutes(30),
        ]);

        // Send verification email
        Mail::to($freelancer->email)
            ->send(new VerificationCodeMail($freelancer->verification_code));

        return response()->json([
            'message' => 'Freelancer registered successfully. Verification code sent to email.',
            'data' => new FreelancerResource($freelancer)
        ], 201);
    }

    /**
     * Verify email with code
     */
    public function verifyEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:freelancers,email',
            'code' => 'required|string|size:6'
        ]);

        $freelancer = Freelancer::where('email', $request->email)->first();

        // Check if code matches and isn't expired
        if (
            !$freelancer->verification_code ||
            $freelancer->verification_code !== $request->code ||
            ($freelancer->verification_code_expires_at && Carbon::now()->gt($freelancer->verification_code_expires_at))
        ) {
            return response()->json([
                'message' => 'Invalid or expired verification code'
            ], 422);
        }

        // Mark as verified
        $freelancer->update([
            'email_verified_at' => Carbon::now(),
            'verification_code' => null,
            'verification_code_expires_at' => null
        ]);

        return response()->json([
            'message' => 'Email verified successfully',
            'data' => new FreelancerResource($freelancer)
        ]);
    }

    /**
     * Resend verification code
     */
    public function resendVerificationCode(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:freelancers,email'
        ]);

        $freelancer = Freelancer::where('email', $request->email)->first();

        // Generate new code
        $freelancer->update([
            'verification_code' => Str::random(6),
            'verification_code_expires_at' => Carbon::now()->addMinutes(30)
        ]);

        Mail::to($freelancer->email)
            ->send(new VerificationCodeMail($freelancer->verification_code));

        return response()->json([
            'message' => 'New verification code sent'
        ]);
    }

    /**
     * Display the specified freelancer
     */
    public function show(Freelancer $freelancer)
    {
        return new FreelancerResource($freelancer);
    }

    /**
     * Update freelancer profile
     */
    public function update(UpdateFreelancerRequest $request, Freelancer $freelancer)
    {
        $validated = $request->validated();

        // Only update password if provided
        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $freelancer->update($validated);

        return response()->json([
            'message' => 'Freelancer updated successfully',
            'data' => new FreelancerResource($freelancer)
        ]);
    }

    /**
     * Delete freelancer account
     */
    public function destroy(Freelancer $freelancer)
    {
        $freelancer->delete();

        return response()->json([
            'message' => 'Freelancer deleted successfully'
        ]);
    }

    /**
     * Freelancer login
     */

    public function login(Request $request)
    {
        // Validate with better error messages
        $validated = $request->validate([
            'email' => 'required|email:rfc,dns',
            'password' => 'required|string|min:8'
        ]);

        // Trim inputs to avoid whitespace issues
        $email = trim($validated['email']);
        $password = trim($validated['password']);

        // Find freelancer with case-insensitive email match
        $freelancer = Freelancer::where('email', 'like', $email)->first();

        // Generic error message to prevent user enumeration
        $errorResponse = [
            'message' => 'The provided credentials are incorrect',
            'success' => false
        ];

        if (!$freelancer) {
            return response()->json($errorResponse, 401);
        }

        // Check password with timing safe comparison
        if (!Hash::check($password, $freelancer->password)) {
            // Log failed attempt (add this to your channels)
            \Log::warning('Failed login attempt for email: ' . $email);
            return response()->json($errorResponse, 401);
        }

        // Check email verification
        if (!$freelancer->email_verified_at) {
            return response()->json([
                'message' => 'Please verify your email address before logging in',
                'requires_verification' => true,
                'success' => false
            ], 403);
        }

        // Revoke existing tokens (optional security enhancement)
        $freelancer->tokens()->delete();

        // Create token with more identifiable name
        $token = $freelancer->createToken(
            'freelancer_auth_' . now()->format('Ymd_His')
        )->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'data' => new FreelancerResource($freelancer),
            'success' => true
        ]);
    }

    /**
     * Freelancer logout
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Successfully logged out'
        ]);
    }

    /**
     * Get authenticated freelancer profile
     */
    public function profile()
    {
        $freelancer = auth()->user()->load('workExperiences');

        return response()->json([
            'message' => 'Freelancer profile retrieved successfully',
            'data' => $freelancer
        ]);
    }
}
