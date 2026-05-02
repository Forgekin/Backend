<?php

namespace App\Http\Controllers;

use App\Models\Employer;
use App\Models\Freelancer;
use App\Models\Job;
use App\Models\JobPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Notification;
use App\Notifications\NewEmployerRegistered;
use App\Notifications\EmployerRegistered;
use App\Notifications\EmployerApproved;
use App\Notifications\EmployerVerificationRevoked;

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

        // Notify the admin — failures shouldn't block registration.
        try {
            Notification::route('mail', config('app.admin_email'))
                ->notify(new NewEmployerRegistered($employer));
        } catch (\Throwable $e) {
            \Log::error('Admin new-employer email failed', [
                'employer_id' => $employer->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Acknowledge registration to the employer themselves.
        try {
            $employer->notify(new EmployerRegistered($employer));
        } catch (\Throwable $e) {
            \Log::error('Employer welcome email failed', [
                'employer_id' => $employer->id,
                'error' => $e->getMessage(),
            ]);
        }

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
     * @bodyParam first_name string Optional. Example: Leslie
     * @bodyParam last_name string Optional. Example: Brown
     * @bodyParam company_name string Optional (must be unique). Example: TechVision Solutions
     * @bodyParam contact string Optional (max 20). Example: +233 24 123 4567
     * @bodyParam email string Optional (must be unique). Example: leslie@techvision.com
     * @bodyParam business_type string Optional. One of: Startup, SME, Corporation. Example: SME
     * @bodyParam company_logo file|string Optional. Either a multipart image file (jpeg,jpg,png,webp,svg; max 5MB) or a base64 data URI (e.g. "data:image/png;base64,..."). Example: data:image/png;base64,iVBORw0KGg...
     * @bodyParam industry string Optional (max 255). Example: Technology & Software
     * @bodyParam company_size string Optional (max 100). Example: 50-200 employees
     * @bodyParam location string Optional (max 255). Example: Accra, Ghana
     * @bodyParam website string Optional URL. Example: www.techvision.com
     * @bodyParam founded string Optional 4-digit year. Example: 2018
     * @bodyParam about string Optional company description. Example: TechVision Solutions is a leading...
     * @bodyParam specialties string[] Optional array of specialty tags. Example: ["Web Development","Mobile Apps","UI/UX Design"]
     *
     * @response 200 scenario="Updated" {"success":true,"message":"Employer updated successfully.","data":{}}
     * @response 403 scenario="Unauthorized" {"success":false,"message":"Unauthorized."}
     * @response 422 scenario="Validation error" {"message":"The founded must be a 4-digit year.","errors":{}}
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
            'contact' => 'sometimes|string|max:20',
            'email' => 'sometimes|email:rfc|unique:employers,email,' . $employer->id,
            'business_type' => 'sometimes|in:Startup,SME,Corporation',
            'industry' => 'sometimes|nullable|string|max:255',
            'company_size' => 'sometimes|nullable|string|max:100',
            'location' => 'sometimes|nullable|string|max:255',
            'website' => 'sometimes|nullable|string|max:255',
            'founded' => 'sometimes|nullable|digits:4',
            'about' => 'sometimes|nullable|string',
            'specialties' => 'sometimes|nullable|array',
            'specialties.*' => 'string|max:100',
            'company_logo' => [
                'sometimes',
                'nullable',
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->hasFile($attribute)) {
                        $file = $request->file($attribute);
                        if (!in_array(strtolower($file->getClientOriginalExtension()), ['jpeg','jpg','png','webp','svg'])) {
                            $fail('The company logo must be a jpeg, jpg, png, webp, or svg file.');
                        }
                        if ($file->getSize() > 5 * 1024 * 1024) {
                            $fail('The company logo may not be greater than 5MB.');
                        }
                    } elseif (is_string($value) && $value !== '') {
                        if (!preg_match('/^data:image\/(jpeg|jpg|png|webp|svg\+xml);base64,/', $value)) {
                            $fail('The company logo must be a valid base64-encoded image data URI.');
                        }
                    }
                },
            ],
        ]);

        $logoInput = $request->input('company_logo');
        unset($validated['company_logo']);

        $employer->fill($validated)->save();

        $companySlug = Str::slug($employer->company_name) ?: ('employer-' . $employer->id);

        if ($request->hasFile('company_logo')) {
            $this->deleteExistingLogo($employer);

            $file = $request->file('company_logo');
            $ext = strtolower($file->getClientOriginalExtension());
            $filename = $companySlug . '.' . $ext;
            $path = $file->storeAs('company_logos', $filename, 'public');

            $employer->update(['company_logo' => $path]);
        } elseif (is_string($logoInput) && Str::startsWith($logoInput, 'data:image/')) {
            if (preg_match('/^data:image\/(jpeg|jpg|png|webp|svg\+xml);base64,(.+)$/', $logoInput, $m)) {
                $this->deleteExistingLogo($employer);

                $ext = $m[1] === 'svg+xml' ? 'svg' : ($m[1] === 'jpeg' ? 'jpg' : $m[1]);
                $decoded = base64_decode($m[2], true);

                if ($decoded !== false) {
                    $path = 'company_logos/' . $companySlug . '.' . $ext;
                    Storage::disk('public')->put($path, $decoded);
                    $employer->update(['company_logo' => $path]);
                }
            }
        } elseif ($request->exists('company_logo') && $logoInput === null) {
            $this->deleteExistingLogo($employer);
            $employer->update(['company_logo' => null]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Employer updated successfully.',
            'data' => $employer->fresh()
        ]);
    }

    protected function deleteExistingLogo(Employer $employer): void
    {
        if (!$employer->company_logo) {
            return;
        }
        $oldPath = ltrim(preg_replace('#^/?storage/#', '', $employer->company_logo), '/');
        Storage::disk('public')->delete($oldPath);
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

        $this->deleteExistingLogo($employer);

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

        try {
            $employer->notify(new EmployerApproved($employer));
        } catch (\Throwable $e) {
            \Log::error('Employer-approved email failed', [
                'employer_id' => $employer->id,
                'error' => $e->getMessage(),
            ]);
        }

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
        // Skip the email if they were already inactive — re-revoking shouldn't
        // re-notify them.
        $wasActive = $employer->verification_status === 'active';

        $employer->update(['verification_status' => 'inactive']);
        $employer->tokens()->delete();

        if ($wasActive) {
            try {
                $employer->notify(new EmployerVerificationRevoked($employer));
            } catch (\Throwable $e) {
                \Log::error('Employer-revoked email failed', [
                    'employer_id' => $employer->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

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

    /**
     * List freelancers assigned to this employer's jobs
     *
     * Returns the distinct freelancers who are assigned to (or have completed) jobs posted by the authenticated employer, with per-freelancer counts and total spend. Optional status filter narrows the result to currently-active assignments or completed-only.
     *
     * @group Employer Profile
     * @authenticated
     *
     * @urlParam employer integer required The employer ID (must match authenticated user). Example: 3
     * @queryParam status string Optional filter: active (assigned|in_progress|on_hold), completed (done). Example: active
     * @queryParam per_page integer Results per page (max 100). Example: 15
     *
     * @response 200 scenario="Success" {"success":true,"data":{}}
     * @response 403 scenario="Unauthorized" {"success":false,"message":"Unauthorized."}
     */
    public function freelancers(Request $request, Employer $employer)
    {
        if (auth()->id() !== $employer->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.'
            ], 403);
        }

        $status = $request->input('status');

        $jobsQuery = Job::where('employer_id', $employer->id)
            ->whereNotNull('assigned_freelancer_id');

        if ($status === 'active') {
            $jobsQuery->whereIn('status', ['assigned', 'in_progress', 'on_hold']);
        } elseif ($status === 'completed') {
            $jobsQuery->where('status', 'done');
        }

        $freelancerIds = (clone $jobsQuery)
            ->pluck('assigned_freelancer_id')
            ->unique()
            ->values();

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $paginator = Freelancer::whereIn('id', $freelancerIds)
            ->orderBy('first_name')
            ->paginate($perPage);

        $items = $paginator->getCollection()->map(function (Freelancer $f) use ($employer) {
            $counts = Job::where('employer_id', $employer->id)
                ->where('assigned_freelancer_id', $f->id)
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            $spend = (float) JobPayment::where('employer_id', $employer->id)
                ->where('freelancer_id', $f->id)
                ->where('status', 'paid')
                ->sum('gross');

            $lastActivity = Job::where('employer_id', $employer->id)
                ->where('assigned_freelancer_id', $f->id)
                ->max('updated_at');

            return [
                'id' => $f->id,
                'full_name' => implode(' ', array_filter([$f->first_name, $f->other_names, $f->last_name])),
                'email' => $f->email,
                'contact' => $f->contact,
                'profession' => $f->profession,
                'profile_image_url' => $f->profile_image
                    ? asset('storage/' . ltrim(preg_replace('#^/?storage/#', '', $f->profile_image), '/'))
                    : null,
                'verification_status' => $f->email_verified_at ? 'verified' : 'pending',
                'jobs_in_progress' => (int) (($counts['in_progress'] ?? 0) + ($counts['assigned'] ?? 0)),
                'jobs_on_hold' => (int) ($counts['on_hold'] ?? 0),
                'jobs_completed_for_you' => (int) ($counts['done'] ?? 0),
                'total_spend_on_freelancer' => round($spend, 2),
                'last_activity' => $lastActivity
                    ? \Illuminate\Support\Carbon::parse($lastActivity)->format('Y-m-d')
                    : null,
            ];
        });

        $paginator->setCollection($items);

        return response()->json([
            'success' => true,
            'data' => $paginator,
        ]);
    }
}
