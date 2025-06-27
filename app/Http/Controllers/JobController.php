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

        // Search by title or description
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%$search%")
                    ->orWhere('description', 'like', "%$search%");
            });
        }

        $jobs = $query->latest()->paginate($request->input('per_page', 10));

        return response()->json([
            'success' => true,
            'data' => $jobs
        ]);
    }

    // Create a new job
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'skills' => 'required|in:Driving,Cleaning,Farm Laborer,Welding,Artisan-Ship,Carpentry,Masonry,Plumbing,Tilling,Auto Electrician',
            'deadline' => 'required|date|after_or_equal:today',
            'shift_type' => 'required|in:Morning,Afternoon,Night,Any Shift',
            'budget_min' => 'nullable|numeric|min:0',
            'budget_max' => 'nullable|numeric|min:0',
        ]);

        // Get authenticated employer
        $employer = Auth::user();

        $validated['employer_id'] = $employer->id;

        $job = Job::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Job created successfully.',
            'data' => $job
        ], 201);
    }

    // Show a specific job
    public function show($id)
    {
        $job = Job::find($id);

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

    // Update a job (only by the employer who owns it)
    public function update(Request $request, $id)
    {
        $job = Job::find($id);

        if (!$job) {
            return response()->json(['success' => false, 'message' => 'Job not found.'], 404);
        }

        // Check job ownership
        if ($job->employer_id !== Auth::id()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'skills' => 'sometimes|in:Driving,Cleaning,Farm Laborer,Welding,Artisan-Ship,Carpentry,Masonry,Plumbing,Tilling,Auto Electrician',
            'deadline' => 'sometimes|date|after_or_equal:today',
            'shift_type' => 'sometimes|in:Morning,Afternoon,Night,Any Shift',
            'budget_min' => 'nullable|numeric|min:0',
            'budget_max' => 'nullable|numeric|min:0',
            'status' => 'sometimes|in:open,closed,pending',
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
