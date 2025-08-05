<?php

namespace App\Http\Controllers;

use App\Models\Freelancer;
use App\Models\Skill;
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
        $freelancer->load([
            'skills',
            'workExperiences',
            'shifts' // Assuming this is the relationship name
        ]);

        return new FreelancerResource($freelancer);
    }


    /**
     * Update freelancer profile
     */
    public function update(Request $request, $freelancerId)
    {
        // Fetch the authenticated freelancer
        $freelancer = auth()->user();

        // Optional: Ensure the ID in the URL matches the authenticated user
        if ((int) $freelancer->id !== (int) $freelancerId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'fullname' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255|unique:freelancers,email,' . $freelancerId, // ✅ Still validated
            'contact' => 'nullable|string|max:20',
            'gender' => 'nullable|in:male,female,other',
            'dob' => 'nullable|date',
            'profession' => 'nullable|string|max:255',
            'password' => 'nullable|string|min:6|confirmed', // ✅ Still validated

            'skills' => 'array|nullable',
            'skills.*' => 'string|max:100',

            'work_experiences' => 'array|nullable',
            'work_experiences.*.role' => 'required|string|max:255',
            'work_experiences.*.company_name' => 'required|string|max:255',
            'work_experiences.*.start_date' => 'required|date',
            'work_experiences.*.end_date' => 'nullable|date|after_or_equal:work_experiences.*.start_date',
            'work_experiences.*.description' => 'nullable|string',

            'shift_preferences' => 'array|nullable',
            'shift_preferences.*.shift_id' => 'required|integer|exists:shifts,id',
            'shift_preferences.*.start_time' => 'required|date_format:H:i:s',
            'shift_preferences.*.end_time' => 'required|date_format:H:i:s',
        ]);

        // ✅ Update basic profile (excluding email and password)
        $freelancer->update([
            'fullname' => $data['fullname'] ?? $freelancer->fullname,
            'contact' => $data['contact'] ?? $freelancer->contact,
            'gender' => $data['gender'] ?? $freelancer->gender,
            'dob' => $data['dob'] ?? $freelancer->dob,
            'profession' => $data['profession'] ?? $freelancer->profession,
            // 'email' and 'password' deliberately excluded
        ]);

        // ✅ Sync or attach skills
        if (!empty($data['skills'])) {
            $skillIds = [];
            foreach ($data['skills'] as $skillName) {
                $skill = Skill::firstOrCreate(['name' => $skillName]);
                $skillIds[] = $skill->id;
            }
            $freelancer->skills()->syncWithoutDetaching($skillIds);
        }

        // ✅ Add work experiences
        if (!empty($data['work_experiences'])) {
            foreach ($data['work_experiences'] as $exp) {
                $freelancer->workExperiences()->create($exp);
            }
        }

        // ✅ Replace shift preferences (only shift IDs are stored)
        if (!empty($data['shift_preferences'])) {
            $shiftIds = collect($data['shift_preferences'])->pluck('shift_id')->toArray();
            $freelancer->shifts()->sync($shiftIds);
        }

        return response()->json([
            'message' => 'Profile updated successfully',
            'freelancer' => $freelancer->load(['skills', 'workExperiences', 'shifts']),
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

    // Delete Work Experience
    public function deleteWorkExperience(Freelancer $freelancer, $experienceId)
    {
        // Authorize
        if (auth()->id() !== $freelancer->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $experience = $freelancer->workExperiences()->find($experienceId);

        if (!$experience) {
            return response()->json(['message' => 'Work experience not found'], 404);
        }

        $experience->delete();

        return response()->json(['message' => 'Work experience deleted successfully']);
    }

    // Detach Skill
    public function detachSkill(Freelancer $freelancer, $skillId)
    {
        // Authorize
        if (auth()->id() !== $freelancer->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$freelancer->skills()->where('skills.id', $skillId)->exists()) {
            return response()->json(['message' => 'Skill not found on freelancer'], 404);
        }

        $freelancer->skills()->detach($skillId);

        return response()->json(['message' => 'Skill detached successfully']);
    }



}
