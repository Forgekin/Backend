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
use App\Models\Job;
use App\Models\{WorkExperience, Shift};
use Illuminate\Http\UploadedFile;


class FreelancerController extends Controller
{
    /**
     * List freelancers
     *
     * Returns a paginated list of freelancers. Supports search by name, email, or contact and filtering by verification status.
     *
     * @group Freelancer Profile
     * @unauthenticated
     *
     * @queryParam search string Search by first_name, last_name, other_names, email, or contact. Example: John
     * @queryParam verified boolean If present, returns only email-verified freelancers. Example: 1
     * @queryParam per_page integer Number of results per page (max 100). Example: 15
     *
     * @response 200 scenario="Success" {"data":[{"id":1,"first_name":"John","last_name":"Doe"}],"links":{},"meta":{}}
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

        // Pagination with default 15 per page, capped at 100
        $perPage = min((int) $request->input('per_page', 15), 100);
        $freelancers = $query->latest()->paginate($perPage);

        return FreelancerResource::collection($freelancers);
    }

    /**
     * Register freelancer
     *
     * Creates a new freelancer account and sends a 6-character email verification code. The code expires in 30 minutes.
     *
     * @group Freelancer Registration & Verification
     * @unauthenticated
     *
     * @bodyParam first_name string required The freelancer's first name. Example: John
     * @bodyParam last_name string required The freelancer's last name. Example: Doe
     * @bodyParam other_names string Optional middle/other names. Example: Michael
     * @bodyParam email string required A unique, valid email address. Example: john@gmail.com
     * @bodyParam contact string required Phone number (max 15 chars). Example: 0551234567
     * @bodyParam password string required Min 8 characters. Example: Password1!
     * @bodyParam password_confirmation string required Must match password. Example: Password1!
     * @bodyParam gender string required One of: male, female, other. Example: male
     * @bodyParam dob string required Date of birth (must be 18+). Example: 2000-01-15
     *
     * @response 201 scenario="Created" {"message":"Freelancer registered successfully. Verification code sent to email.","data":{"id":1,"first_name":"John","last_name":"Doe"}}
     * @response 422 scenario="Validation error" {"message":"The email has already been taken.","errors":{"email":["The email has already been taken."]}}
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

        // Send verification email — don't fail the whole registration if SMTP is down.
        // The freelancer can request a new code via /resend-verification.
        try {
            Mail::to($freelancer->email)
                ->send(new VerificationCodeMail($freelancer->verification_code));
        } catch (\Throwable $e) {
            \Log::error('Verification email failed on registration', [
                'email' => $freelancer->email,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Freelancer registered successfully. Verification code sent to email.',
            'data' => new FreelancerResource($freelancer)
        ], 201);
    }

    /**
     * Verify email
     *
     * Verifies a freelancer's email using the 6-character code sent during registration. Codes expire after 30 minutes.
     *
     * @group Freelancer Registration & Verification
     * @unauthenticated
     *
     * @bodyParam email string required The freelancer's registered email. Example: john@gmail.com
     * @bodyParam code string required The 6-character verification code. Example: ABC123
     *
     * @response 200 scenario="Verified" {"message":"Email verified successfully","data":{"id":1,"first_name":"John"}}
     * @response 422 scenario="Invalid/expired code" {"message":"Invalid or expired verification code"}
     * @response 422 scenario="Validation error" {"message":"The selected email is invalid.","errors":{"email":["The selected email is invalid."]}}
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
     *
     * Generates a new 6-character verification code (expires in 30 minutes) and sends it to the freelancer's email.
     *
     * @group Freelancer Registration & Verification
     * @unauthenticated
     *
     * @bodyParam email string required The freelancer's registered email. Example: john@gmail.com
     *
     * @response 200 scenario="Sent" {"message":"New verification code sent"}
     * @response 422 scenario="Email not found" {"message":"The selected email is invalid.","errors":{"email":["The selected email is invalid."]}}
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

        try {
            Mail::to($freelancer->email)
                ->send(new VerificationCodeMail($freelancer->verification_code));
        } catch (\Throwable $e) {
            \Log::error('Verification email failed on resend', [
                'email' => $freelancer->email,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Could not send verification email right now. Please try again shortly.',
            ], 502);
        }

        return response()->json([
            'message' => 'New verification code sent'
        ]);
    }

    /**
     * Get freelancer
     *
     * Returns a single freelancer with their skills, work experiences, and shift preferences.
     *
     * @group Freelancer Profile
     * @unauthenticated
     *
     * @urlParam freelancer integer required The freelancer ID. Example: 1
     *
     * @response 200 scenario="Success" {"data":{"id":1,"first_name":"John","last_name":"Doe","skills":["PHP","Laravel"],"work_experiences":[],"shift_preferences":[]}}
     * @response 404 scenario="Not found" {"message":"No query results for model [App\\Models\\Freelancer] 999"}
     */
    public function show(Freelancer $freelancer)
    {
        $freelancer->load([
            'skills',
            'workExperiences',
            'shifts'
        ]);

        return new FreelancerResource($freelancer);
    }


    /**
     * Update freelancer profile
     *
     * Updates the authenticated freelancer's profile. Supports updating basic info, skills (syncs), work experiences (replaces all), shift preferences (syncs), profile image, and document uploads. Only the freelancer themselves can update their profile.
     *
     * @group Freelancer Profile
     * @authenticated
     *
     * @urlParam freelancer integer required The freelancer ID (must match authenticated user). Example: 1
     *
     * @bodyParam first_name string Optional. Example: John
     * @bodyParam last_name string Optional. Example: Doe
     * @bodyParam other_names string Optional. Example: Michael
     * @bodyParam contact string Optional (max 20). Example: 0551234567
     * @bodyParam gender string Optional. One of: male, female, other. Example: male
     * @bodyParam dob string Optional date. Example: 2000-01-15
     * @bodyParam profession string Optional. Example: Backend Developer
     * @bodyParam bio string Optional biography. Example: Experienced PHP developer
     * @bodyParam location string Optional. Example: Accra, Ghana
     * @bodyParam hourly_rate number Optional (min 0). Example: 45.00
     * @bodyParam proficiency string Optional. One of: beginner, intermediate, advanced. Example: advanced
     * @bodyParam skills string[] Optional array of skill names (will be synced). Example: ["PHP","Laravel","Docker"]
     * @bodyParam work_experiences object[] Optional array of work experiences (replaces all existing).
     * @bodyParam work_experiences[].role string required Job title. Example: Senior Developer
     * @bodyParam work_experiences[].company_name string required Company name. Example: TechCorp
     * @bodyParam work_experiences[].start_date string required Start date. Example: 2020-01-01
     * @bodyParam work_experiences[].end_date string Optional end date. Example: 2023-12-31
     * @bodyParam work_experiences[].description string Optional description. Example: Built REST APIs
     * @bodyParam shift_preferences object[] Optional shift preferences (will be synced).
     * @bodyParam shift_preferences[].shift_id integer required The shift ID. Example: 1
     * @bodyParam shift_preferences[].start_time string required Format: H:i:s. Example: 08:00:00
     * @bodyParam shift_preferences[].end_time string required Format: H:i:s. Example: 12:00:00
     * @bodyParam profile_image file Optional image (max 5MB).
     * @bodyParam documents file[] Optional documents (pdf,jpeg,jpg,png,doc,docx; max 5MB each).
     *
     * @response 200 scenario="Updated" {"message":"Profile updated successfully","freelancer":{}}
     * @response 403 scenario="Unauthorized" {"message":"Unauthorized"}
     * @response 422 scenario="Invalid dates" {"message":"End date cannot be before start date."}
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

        if (isset($data['skills'])) {
            $skillIds = [];
            foreach ($data['skills'] as $skillName) {
                $skill = Skill::firstOrCreate(['name' => $skillName]);
                $skillIds[] = $skill->id;
            }
            $freelancer->skills()->sync($skillIds);
        }

        if (isset($data['work_experiences'])) {
            $freelancer->workExperiences()->delete();
            foreach ($data['work_experiences'] as $exp) {
                if (!empty($exp['end_date']) && $exp['end_date'] < $exp['start_date']) {
                    return response()->json([
                        'message' => 'End date cannot be before start date.'
                    ], 422);
                }
                $freelancer->workExperiences()->create($exp);
            }
        }

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

        $nameSlug = Str::slug(trim(($freelancer->first_name ?? '') . ' ' . ($freelancer->last_name ?? ''))) ?: ('freelancer-' . $freelancer->id);

        if ($request->hasFile('profile_image')) {
            if ($freelancer->profile_image) {
                $oldPath = ltrim(str_replace('/storage/', '', $freelancer->profile_image), '/');
                Storage::disk('public')->delete($oldPath);
            }
            $ext = $request->file('profile_image')->getClientOriginalExtension();
            $filename = $nameSlug . '.' . $ext;
            $path = $request->file('profile_image')
                ->storeAs('profile_images', $filename, 'public');
            $freelancer->update([
                'profile_image' => $path
            ]);
        }

        if ($request->hasFile('documents')) {
            foreach ($request->file('documents') as $file) {
                $ext = $file->getClientOriginalExtension();
                $filename = $nameSlug . '-' . now()->format('YmdHis') . '-' . Str::random(4) . '.' . $ext;
                $path = $file->storeAs('documents', $filename, 'public');
                $freelancer->documents()->create([
                    'file_path' => $path,
                    'file_type' => $ext,
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
     * Delete freelancer
     *
     * Permanently deletes the freelancer account. Only the freelancer themselves can delete their account.
     *
     * @group Freelancer Profile
     * @authenticated
     *
     * @urlParam freelancer integer required The freelancer ID. Example: 1
     *
     * @response 200 scenario="Deleted" {"message":"Freelancer deleted successfully"}
     * @response 403 scenario="Unauthorized" {"message":"Unauthorized"}
     */
    public function destroy(Freelancer $freelancer)
    {
        if (auth()->id() !== $freelancer->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($freelancer->profile_image) {
            $oldPath = ltrim(preg_replace('#^/?storage/#', '', $freelancer->profile_image), '/');
            Storage::disk('public')->delete($oldPath);
        }

        foreach ($freelancer->documents as $doc) {
            $path = ltrim(preg_replace('#^/?storage/#', '', $doc->file_path), '/');
            Storage::disk('public')->delete($path);
        }

        $freelancer->delete();

        return response()->json([
            'message' => 'Freelancer deleted successfully'
        ]);
    }

    /**
     * Delete a freelancer document
     *
     * Deletes a single uploaded document belonging to the authenticated freelancer, removing both the DB row and the file on disk.
     *
     * @group Freelancer Profile
     * @authenticated
     *
     * @urlParam freelancer integer required The freelancer ID (must match authenticated user). Example: 1
     * @urlParam document integer required The document ID to delete. Example: 7
     *
     * @response 200 scenario="Deleted" {"success":true,"message":"Document deleted successfully."}
     * @response 403 scenario="Unauthorized" {"success":false,"message":"Unauthorized."}
     * @response 404 scenario="Not found" {"success":false,"message":"Document not found for this freelancer."}
     */
    public function deleteDocument(Freelancer $freelancer, FreelancerDocument $document)
    {
        if (auth()->id() !== $freelancer->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.'
            ], 403);
        }

        if ($document->freelancer_id !== $freelancer->id) {
            return response()->json([
                'success' => false,
                'message' => 'Document not found for this freelancer.'
            ], 404);
        }

        $path = ltrim(preg_replace('#^/?storage/#', '', $document->file_path), '/');
        Storage::disk('public')->delete($path);

        $document->delete();

        return response()->json([
            'success' => true,
            'message' => 'Document deleted successfully.'
        ]);
    }

    /**
     * Freelancer login
     *
     * Authenticates a freelancer and returns a Sanctum bearer token. Email must be verified first. Rate limited to 5 attempts per minute.
     *
     * @group Freelancer Authentication
     * @unauthenticated
     *
     * @bodyParam email string required The freelancer's email. Example: john@gmail.com
     * @bodyParam password string required Min 8 characters. Example: Password1!
     *
     * @response 200 scenario="Success" {"message":"Login successful","token":"1|abc123...","data":{"id":1,"first_name":"John"},"success":true}
     * @response 401 scenario="Bad credentials" {"message":"The provided credentials are incorrect","success":false}
     * @response 403 scenario="Unverified email" {"message":"Please verify your email address before logging in","requires_verification":true,"success":false}
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

        $freelancer = Freelancer::where('email', 'like', $email)->first();

        $errorResponse = [
            'message' => 'The provided credentials are incorrect',
            'success' => false
        ];

        if (!$freelancer) {
            return response()->json($errorResponse, 401);
        }

        if (!Hash::check($password, $freelancer->password)) {
            \Log::warning('Failed login attempt for email: ' . $email);
            return response()->json($errorResponse, 401);
        }

        if (!$freelancer->email_verified_at) {
            return response()->json([
                'message' => 'Please verify your email address before logging in',
                'requires_verification' => true,
                'success' => false
            ], 403);
        }

        $freelancer->tokens()->delete();

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
     *
     * Revokes the current access token.
     *
     * @group Freelancer Authentication
     * @authenticated
     *
     * @response 200 scenario="Success" {"message":"Successfully logged out"}
     * @response 401 scenario="Unauthenticated" {"message":"Unauthenticated."}
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Successfully logged out'
        ]);
    }

    /**
     * Delete work experience
     *
     * Removes a specific work experience entry from the freelancer's profile. Only the freelancer themselves can do this.
     *
     * @group Freelancer Profile
     * @authenticated
     *
     * @urlParam freelancer integer required The freelancer ID. Example: 1
     * @urlParam experience integer required The work experience ID. Example: 5
     *
     * @response 200 scenario="Deleted" {"message":"Work experience deleted successfully"}
     * @response 403 scenario="Unauthorized" {"message":"Unauthorized"}
     * @response 404 scenario="Not found" {"message":"Work experience not found"}
     */
    public function deleteWorkExperience(Freelancer $freelancer, $experienceId)
    {
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

    /**
     * Detach skill
     *
     * Removes a skill from the freelancer's profile. Only the freelancer themselves can do this.
     *
     * @group Freelancer Profile
     * @authenticated
     *
     * @urlParam freelancer integer required The freelancer ID. Example: 1
     * @urlParam skill integer required The skill ID to detach. Example: 3
     *
     * @response 200 scenario="Detached" {"message":"Skill detached successfully"}
     * @response 403 scenario="Unauthorized" {"message":"Unauthorized"}
     * @response 404 scenario="Not attached" {"message":"Skill not found on freelancer"}
     */
    public function detachSkill(Freelancer $freelancer, $skillId)
    {
        if (auth()->id() !== $freelancer->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$freelancer->skills()->where('skills.id', $skillId)->exists()) {
            return response()->json(['message' => 'Skill not found on freelancer'], 404);
        }

        $freelancer->skills()->detach($skillId);

        return response()->json(['message' => 'Skill detached successfully']);
    }

    /**
     * List jobs assigned to this freelancer
     *
     * Returns a paginated list of jobs assigned to the authenticated freelancer. Optional filters: status, rate_type, active_only. Each job includes the employer relation.
     *
     * @group Freelancer Profile
     * @authenticated
     *
     * @urlParam freelancer integer required The freelancer ID (must match authenticated user). Example: 1
     * @queryParam status string Filter: assigned, in_progress, on_hold, done, approved. Example: in_progress
     * @queryParam rate_type string Filter: hourly, fixed. Example: hourly
     * @queryParam active_only boolean If 1, only returns jobs with status assigned|in_progress|on_hold. Example: 1
     * @queryParam per_page integer Results per page (max 100). Example: 15
     *
     * @response 200 scenario="Success" {"success":true,"data":{}}
     * @response 403 scenario="Unauthorized" {"success":false,"message":"Unauthorized."}
     */
    public function assignedJobs(Request $request, Freelancer $freelancer)
    {
        if (auth()->id() !== $freelancer->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.'
            ], 403);
        }

        $query = Job::where('assigned_freelancer_id', $freelancer->id)
            ->with('employer');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('rate_type')) {
            $query->where('rate_type', $request->input('rate_type'));
        }

        if ($request->boolean('active_only')) {
            $query->whereIn('status', ['assigned', 'in_progress', 'on_hold']);
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $jobs = $query->orderByDesc('updated_at')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $jobs,
        ]);
    }
}
