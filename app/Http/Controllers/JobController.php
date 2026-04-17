<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Job;
use App\Models\Freelancer;
use Illuminate\Support\Facades\Auth;

class JobController extends Controller
{
    /**
     * List jobs
     *
     * Returns a paginated, filterable list of job postings. Supports search by title/description/skills, and filters for rate type, experience level, shift type, status, employer, budget range, and active-only.
     *
     * @group Jobs
     * @unauthenticated
     *
     * @queryParam search string Search in title, description, skills. Example: Laravel
     * @queryParam rate_type string Filter: hourly or fixed. Example: hourly
     * @queryParam experience_level string Filter: beginner, intermediate, advanced. Example: advanced
     * @queryParam shift_type string Filter: Morning, Afternoon, Night, Any Shift. Example: Morning
     * @queryParam status string Filter: new, pending_approval, done, assigned, in_progress, on_hold, approved. Example: new
     * @queryParam employer_id integer Filter by employer ID. Example: 1
     * @queryParam min_budget number Filter jobs where max_budget >= this value. Example: 20
     * @queryParam max_budget number Filter jobs where min_budget <= this value. Example: 100
     * @queryParam active_only boolean If true, only returns jobs with deadline >= today. Example: 1
     * @queryParam per_page integer Results per page (max 100). Example: 10
     *
     * @response 200 scenario="Success" {"success":true,"data":{"data":[],"links":{},"meta":{}}}
     */
    public function index(Request $request)
    {
        $query = Job::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('skills', 'like', "%{$search}%");
            });
        }

        if ($request->filled('rate_type')) {
            $query->where('rate_type', $request->rate_type);
        }

        if ($request->filled('experience_level')) {
            $query->where('experience_level', $request->experience_level);
        }

        if ($request->filled('shift_type')) {
            $query->where('shift_type', $request->shift_type);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('employer_id')) {
            $query->where('employer_id', $request->employer_id);
        }

        if ($request->filled('min_budget')) {
            $query->where('max_budget', '>=', $request->min_budget);
        }

        if ($request->filled('max_budget')) {
            $query->where('min_budget', '<=', $request->max_budget);
        }

        if ($request->boolean('active_only')) {
            $query->whereDate('deadline', '>=', now());
        }

        $query->latest();

        $perPage = min((int) $request->input('per_page', 10), 100);
        $jobs = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $jobs
        ]);
    }

    /**
     * Get job
     *
     * Returns a single job posting with the employer relationship.
     *
     * @group Jobs
     * @unauthenticated
     *
     * @urlParam id integer required The job ID. Example: 1
     *
     * @response 200 scenario="Success" {"success":true,"data":{"id":1,"title":"Senior Laravel Developer","employer":{}}}
     * @response 404 scenario="Not found" {"success":false,"message":"Job not found."}
     */
    public function show($id)
    {
        $job = Job::with('employer')->find($id);

        if (!$job) {
            return response()->json([
                'success' => false,
                'message' => 'Job not found.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $job
        ]);
    }

    /**
     * Create job
     *
     * Creates a new job posting. The authenticated employer is automatically set as the owner. Status defaults to `new`.
     *
     * @group Jobs
     * @authenticated
     *
     * @bodyParam title string required Job title (max 255). Example: Senior Laravel Developer
     * @bodyParam description string required Full job description. Example: Build REST APIs for a fintech platform.
     * @bodyParam skills string required Comma-separated skills. Example: PHP, Laravel, MySQL
     * @bodyParam rate_type string required One of: hourly, fixed. Example: hourly
     * @bodyParam experience_level string required One of: beginner, intermediate, advanced. Example: advanced
     * @bodyParam min_budget number Optional minimum budget (>= 0). Example: 30
     * @bodyParam max_budget number Optional maximum budget (>= min_budget). Example: 80
     * @bodyParam deadline string required Future date (YYYY-MM-DD). Example: 2026-06-30
     * @bodyParam estimated_duration string required Duration estimate. Example: 3 months
     * @bodyParam shift_type string required One of: Morning, Afternoon, Night, Any Shift. Example: Morning
     *
     * @response 201 scenario="Created" {"success":true,"message":"Job created successfully.","data":{"id":1,"title":"Senior Laravel Developer","status":"new"}}
     * @response 401 scenario="Unauthenticated" {"message":"Unauthenticated."}
     * @response 422 scenario="Validation error" {"message":"The title field is required.","errors":{}}
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'skills' => 'required|string',
            'rate_type' => 'required|in:hourly,fixed',
            'experience_level' => 'required|in:beginner,intermediate,advanced',
            'min_budget' => 'nullable|numeric|min:0',
            'max_budget' => 'nullable|numeric|min:0|gte:min_budget',
            'deadline' => 'required|date|after_or_equal:today',
            'estimated_duration' => 'required|string|max:255',
            'shift_type' => 'required|in:Morning,Afternoon,Night,Any Shift',
        ]);

        $employer = Auth::user();
        $validated['employer_id'] = $employer->id;
        $validated['status'] = 'new';

        $job = Job::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Job created successfully.',
            'data' => $job
        ], 201);
    }

    /**
     * Update job
     *
     * Updates a job posting. Only the employer who created the job can update it.
     *
     * @group Jobs
     * @authenticated
     *
     * @urlParam id integer required The job ID. Example: 1
     *
     * @bodyParam title string Optional. Example: Updated Job Title
     * @bodyParam description string Optional. Example: Updated description.
     * @bodyParam skills string Optional. Example: PHP, Docker
     * @bodyParam rate_type string Optional. One of: hourly, fixed. Example: fixed
     * @bodyParam experience_level string Optional. One of: beginner, intermediate, advanced. Example: intermediate
     * @bodyParam min_budget number Optional. Example: 25
     * @bodyParam max_budget number Optional (>= min_budget). Example: 60
     * @bodyParam deadline string Optional future date. Example: 2026-07-15
     * @bodyParam estimated_duration string Optional. Example: 2 months
     * @bodyParam shift_type string Optional. One of: Morning, Afternoon, Night, Any Shift. Example: Afternoon
     * @bodyParam status string Optional. One of: new, pending_approval, done, assigned, in_progress, on_hold, approved. Example: in_progress
     *
     * @response 200 scenario="Updated" {"success":true,"message":"Job updated successfully.","data":{}}
     * @response 403 scenario="Not owner" {"success":false,"message":"Unauthorized."}
     * @response 404 scenario="Not found" {"success":false,"message":"Job not found."}
     */
    public function update(Request $request, $id)
    {
        $job = Job::find($id);

        if (!$job) {
            return response()->json([
                'success' => false,
                'message' => 'Job not found.'
            ], 404);
        }

        if ($job->employer_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.'
            ], 403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'skills' => 'sometimes|string',
            'rate_type' => 'sometimes|in:hourly,fixed',
            'experience_level' => 'sometimes|in:beginner,intermediate,advanced',
            'min_budget' => 'nullable|numeric|min:0',
            'max_budget' => 'nullable|numeric|min:0|gte:min_budget',
            'deadline' => 'sometimes|date|after_or_equal:today',
            'estimated_duration' => 'sometimes|string|max:255',
            'shift_type' => 'sometimes|in:Morning,Afternoon,Night,Any Shift',
            'status' => 'sometimes|in:new,pending_approval,done,assigned,in_progress,on_hold,approved',
        ]);

        $job->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Job updated successfully.',
            'data' => $job
        ]);
    }

    /**
     * Delete job
     *
     * Permanently deletes a job posting. Only the employer who created the job can delete it.
     *
     * @group Jobs
     * @authenticated
     *
     * @urlParam id integer required The job ID. Example: 1
     *
     * @response 200 scenario="Deleted" {"success":true,"message":"Job deleted successfully."}
     * @response 403 scenario="Not owner" {"success":false,"message":"Unauthorized."}
     * @response 404 scenario="Not found" {"success":false,"message":"Job not found."}
     */
    public function destroy($id)
    {
        $job = Job::find($id);

        if (!$job) {
            return response()->json(['success' => false, 'message' => 'Job not found.'], 404);
        }

        if ($job->employer_id !== Auth::id()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $job->delete();

        return response()->json([
            'success' => true,
            'message' => 'Job deleted successfully.'
        ]);
    }

    /**
     * Approve job
     *
     * Approves a job posting by setting its status to `approved`. Requires admin authentication with the `jobs.approve` permission.
     *
     * @group Jobs (Admin)
     * @authenticated
     *
     * @urlParam id integer required The job ID. Example: 1
     *
     * @response 200 scenario="Approved" {"success":true,"message":"Job approved successfully.","data":{}}
     * @response 200 scenario="Already approved" {"success":true,"message":"Job is already approved.","data":{}}
     * @response 404 scenario="Not found" {"success":false,"message":"Job not found."}
     */
    public function approve($id)
    {
        $job = Job::find($id);

        if (!$job) {
            return response()->json([
                'success' => false,
                'message' => 'Job not found.'
            ], 404);
        }

        if ($job->status === 'approved') {
            return response()->json([
                'success' => true,
                'message' => 'Job is already approved.',
                'data' => $job
            ]);
        }

        $job->update(['status' => 'approved']);

        return response()->json([
            'success' => true,
            'message' => 'Job approved successfully.',
            'data' => $job->fresh()
        ]);
    }

    /**
     * Assign freelancer to job
     *
     * Assigns a freelancer to a job posting and marks the job as `assigned`. Requires admin authentication with the `jobs.assign` permission.
     *
     * @group Jobs (Admin)
     * @authenticated
     *
     * @urlParam id integer required The job ID. Example: 1
     *
     * @bodyParam freelancer_id integer required The freelancer to assign. Example: 7
     *
     * @response 200 scenario="Assigned" {"success":true,"message":"Freelancer assigned successfully.","data":{}}
     * @response 404 scenario="Job not found" {"success":false,"message":"Job not found."}
     * @response 422 scenario="Validation error" {"message":"The freelancer id field is required.","errors":{}}
     */
    public function assignFreelancer(Request $request, $id)
    {
        $job = Job::find($id);

        if (!$job) {
            return response()->json([
                'success' => false,
                'message' => 'Job not found.'
            ], 404);
        }

        $validated = $request->validate([
            'freelancer_id' => 'required|integer|exists:freelancers,id',
        ]);

        $job->update([
            'assigned_freelancer_id' => $validated['freelancer_id'],
            'status' => 'assigned',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Freelancer assigned successfully.',
            'data' => $job->fresh()
        ]);
    }
}
