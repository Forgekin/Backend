<?php

namespace App\Http\Controllers;

use App\Models\Freelancer;
use App\Models\Skill;
use App\Models\FreelancerDocument;
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
use Illuminate\Support\Facades\Storage;
use App\Models\{WorkExperience, Shift};
use Illuminate\Http\UploadedFile;


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
                $q->where('first_name', 'like', "%$search%")
                    ->orWhere('last_name', 'like', "%$search%")
                    ->orWhere('other_names', 'like', "%$search%")
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
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'other_names' => $validated['other_names'] ?? null,
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
        $freelancer = auth()->user();

        // Authorization
        if ((int) $freelancer->id !== (int) $freelancerId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Validation
        $data = $request->validate([
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'other_names' => 'nullable|string|max:255',
            'contact' => 'nullable|string|max:20',
            'gender' => 'nullable|in:male,female,other',
            'dob' => 'nullable|date',
            'profession' => 'nullable|string|max:255',
            'bio' => 'nullable|string',
            'location' => 'nullable|string|max:255',
            'hourly_rate' => 'nullable|numeric|min:0',
            'proficiency' => 'nullable|in:beginner,intermediate,advanced',

            'skills' => 'array|nullable',
            'skills.*' => 'string|max:100',

            'work_experiences' => 'array|nullable',
            'work_experiences.*.role' => 'required|string|max:255',
            'work_experiences.*.company_name' => 'required|string|max:255',
            'work_experiences.*.start_date' => 'required|date',
            'work_experiences.*.end_date' => 'nullable|date',
            'work_experiences.*.description' => 'nullable|string',

            'shift_preferences' => 'array|nullable',
            'shift_preferences.*.shift_id' => 'required|integer|exists:shifts,id',
            'shift_preferences.*.start_time' => 'required|date_format:H:i:s',
            'shift_preferences.*.end_time' => 'required|date_format:H:i:s',

            'profile_image' => 'nullable|image|max:5120',
            'documents.*' => 'file|mimes:pdf,jpeg,jpg,png,doc,docx|max:5120',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Update Basic Fields
        |--------------------------------------------------------------------------
        */
        $freelancer->update([
            'first_name' => $data['first_name'] ?? $freelancer->first_name,
            'last_name' => $data['last_name'] ?? $freelancer->last_name,
            'other_names' => $data['other_names'] ?? $freelancer->other_names,
            'contact' => $data['contact'] ?? $freelancer->contact,
            'gender' => $data['gender'] ?? $freelancer->gender,
            'dob' => $data['dob'] ?? $freelancer->dob,
            'profession' => $data['profession'] ?? $freelancer->profession,
            'bio' => $data['bio'] ?? $freelancer->bio,
            'location' => $data['location'] ?? $freelancer->location,
            'hourly_rate' => $data['hourly_rate'] ?? $freelancer->hourly_rate,
            'proficiency' => $data['proficiency'] ?? $freelancer->proficiency,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Skills (Many-to-Many)
        |--------------------------------------------------------------------------
        */
        if (isset($data['skills'])) {
            $skillIds = [];

            foreach ($data['skills'] as $skillName) {
                $skill = Skill::firstOrCreate(['name' => $skillName]);
                $skillIds[] = $skill->id;
            }

            // Replace existing skills entirely
            $freelancer->skills()->sync($skillIds);
        }

        /*
        |--------------------------------------------------------------------------
        | Work Experiences (One-to-Many)
        |--------------------------------------------------------------------------
        */
        if (isset($data['work_experiences'])) {

            $freelancer->workExperiences()->delete();

            foreach ($data['work_experiences'] as $exp) {

                // Logical validation for end_date
                if (!empty($exp['end_date']) && $exp['end_date'] < $exp['start_date']) {
                    return response()->json([
                        'message' => 'End date cannot be before start date.'
                    ], 422);
                }

                $freelancer->workExperiences()->create($exp);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Shift Preferences (Pivot With Extra Columns)
        |--------------------------------------------------------------------------
        */
        if (isset($data['shift_preferences'])) {

            $syncData = [];

            foreach ($data['shift_preferences'] as $shift) {
                $syncData[$shift['shift_id']] = [
                    'start_time' => $shift['start_time'],
                    'end_time' => $shift['end_time'],
                ];
            }

            $freelancer->shifts()->sync($syncData);
        }

        /*
        |--------------------------------------------------------------------------
        | Profile Image
        |--------------------------------------------------------------------------
        */
        if ($request->hasFile('profile_image')) {

            // Optional: delete old image
            if ($freelancer->profile_image) {
                $oldPath = str_replace('/storage/', '', $freelancer->profile_image);
                Storage::disk('public')->delete($oldPath);
            }

            $path = $request->file('profile_image')
                ->store('profile_images', 'public');

            $freelancer->update([
                'profile_image' => Storage::url($path)
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Documents
        |--------------------------------------------------------------------------
        */
        if ($request->hasFile('documents')) {

            foreach ($request->file('documents') as $file) {

                $path = $file->store('documents', 'public');

                $freelancer->documents()->create([
                    'file_path' => Storage::url($path),
                    'file_type' => $file->getClientOriginalExtension(),
                    'original_name' => $file->getClientOriginalName(),
                ]);
            }
        }

        return response()->json([
            'message' => 'Profile updated successfully',
            'freelancer' => $freelancer->load([
                'skills',
                'workExperiences',
                'shifts',
                'documents'
            ])
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
