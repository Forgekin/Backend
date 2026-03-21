<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Job;
use Illuminate\Support\Facades\Auth;

class JobController extends Controller
{
    // List all jobs (with optional search and pagination)
    public function index(Request $request)
    {
        $query = Job::query();

        /*
        |--------------------------------------------------------------------------
        | Search (title, description, skills)
        |--------------------------------------------------------------------------
        */
        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('skills', 'like', "%{$search}%");
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Filters
        |--------------------------------------------------------------------------
        */

        // Rate Type (hourly | fixed)
        if ($request->filled('rate_type')) {
            $query->where('rate_type', $request->rate_type);
        }

        // Experience Level
        if ($request->filled('experience_level')) {
            $query->where('experience_level', $request->experience_level);
        }

        // Shift Type
        if ($request->filled('shift_type')) {
            $query->where('shift_type', $request->shift_type);
        }

        // Status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Employer Filter
        if ($request->filled('employer_id')) {
            $query->where('employer_id', $request->employer_id);
        }

        // Budget Range Filter
        if ($request->filled('min_budget')) {
            $query->where('max_budget', '>=', $request->min_budget);
        }

        if ($request->filled('max_budget')) {
            $query->where('min_budget', '<=', $request->max_budget);
        }

        // Deadline Filter (only active jobs before deadline)
        if ($request->boolean('active_only')) {
            $query->whereDate('deadline', '>=', now());
        }

        /*
        |--------------------------------------------------------------------------
        | Sorting
        |--------------------------------------------------------------------------
        */
        $query->latest(); // orders by created_at DESC

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */
        $perPage = $request->input('per_page', 10);

        $jobs = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $jobs
        ]);
    }


    // Show a single job
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

    // Create a new job
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',

            // If you're storing comma-separated skills
            'skills' => 'required|string',

            'rate_type' => 'required|in:hourly,fixed',
            'experience_level' => 'required|in:beginner,intermediate,advanced',

            'min_budget' => 'nullable|numeric|min:0',
            'max_budget' => 'nullable|numeric|min:0|gte:min_budget',

            'deadline' => 'required|date|after_or_equal:today',
            'estimated_duration' => 'required|string|max:255',

            'shift_type' => 'required|in:Morning,Afternoon,Night,Any Shift',
        ]);

        // Get authenticated employer
        $employer = Auth::user();

        $validated['employer_id'] = $employer->id;

        // Default status (since your schema default is 'new')
        $validated['status'] = 'new';

        $job = Job::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Job created successfully.',
            'data' => $job
        ], 201);
    }


    // Update a job (only by the employer who owns it)
    public function update(Request $request, $id)
    {
        $job = Job::find($id);

        if (!$job) {
            return response()->json([
                'success' => false,
                'message' => 'Job not found.'
            ], 404);
        }

        // Ensure only the owner (employer) can update
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


    // Delete a job (only by employer who owns it)
    public function destroy($id)
    {
        $job = Job::find($id);

        if (!$job) {
            return response()->json(['success' => false, 'message' => 'Job not found.'], 404);
        }

        // Check job ownership
        if ($job->employer_id !== Auth::id()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $job->delete();

        return response()->json([
            'success' => true,
            'message' => 'Job deleted successfully.'
        ]);
    }
}
