<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminPerformanceController;
use App\Http\Controllers\FreelancerController;
use App\Http\Controllers\FreelancerDashboardController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\EmployerController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Controllers\UserController;


// Freelancer routes
Route::prefix('freelancers')->group(function () {
    // Public routes
    Route::post('/', [FreelancerController::class, 'store']);
    Route::post('/verify-email', [FreelancerController::class, 'verifyEmail']);
    Route::post('/resend-verification', [FreelancerController::class, 'resendVerificationCode']);
    Route::post('/login', [FreelancerController::class, 'login'])->middleware('throttle:5,1');
    Route::get('/', action: [FreelancerController::class, 'index']);
    Route::get('/{freelancer}', [FreelancerController::class, 'show']);

    // Protected routes (require auth)
    Route::middleware(['auth:sanctum', 'verified'])->group(function () {
        Route::put('/{freelancer}', [FreelancerController::class, 'update']);
        Route::delete('/{freelancer}', [FreelancerController::class, 'destroy']);
        Route::post('/logout', [FreelancerController::class, 'logout']);
        // For deleting a work experience
        Route::delete('/{freelancer}/work-experiences/{experience}', [FreelancerController::class, 'deleteWorkExperience']);
        // For detaching a skill
        Route::delete('/{freelancer}/skills/{skill}', [FreelancerController::class, 'detachSkill']);
        // For deleting a single uploaded document
        Route::delete('/{freelancer}/documents/{document}', [FreelancerController::class, 'deleteDocument']);
        // Dashboard — stats, earnings, job history, withdrawals
        Route::get('/{id}/dashboard', [FreelancerDashboardController::class, 'index']);
        // Jobs assigned to this freelancer
        Route::get('/{freelancer}/jobs', [FreelancerController::class, 'assignedJobs']);
    });
});

// Forgot Password and Reset Password routes
Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword'])->middleware('throttle:3,1');
Route::post('/reset-password', [PasswordResetController::class, 'resetPassword'])->name('password.update');


// Employer routes
Route::prefix('employers')->group(function () {

    // Public routes
    Route::post('/register', [EmployerController::class, 'register']);
    Route::post('/login', [EmployerController::class, 'login'])->middleware('throttle:5,1');
    Route::get('/', [EmployerController::class, 'index']);
    Route::get('/{employer}', [EmployerController::class, 'show']);

    // Protected routes (require auth)
    Route::middleware(['auth:sanctum', 'verified'])->group(function () {
        Route::put('/{employer}', [EmployerController::class, 'update']);
        Route::delete('/{employer}', [EmployerController::class, 'destroy']);
        Route::post('/logout', [EmployerController::class, 'logout']);
        // Freelancers who work / have worked on this employer's jobs
        Route::get('/{employer}/freelancers', [EmployerController::class, 'freelancers']);
    });
});

// Admin Login
Route::post('/users/login', [UserController::class, 'login'])->middleware('throttle:5,1');



// Job routes
Route::prefix('jobs')->group(function () {
    // Public job listing routes
    Route::get('/', [JobController::class, 'index']);
    Route::get('/{id}', [JobController::class, 'show']);

    // Protected routes for employers
    Route::middleware(['auth:sanctum', 'verified'])->group(function () {
        Route::post('/', [JobController::class, 'store']);
        Route::put('/{id}', [JobController::class, 'update']);
        Route::delete('/{id}', [JobController::class, 'destroy']);
    });

    // Freelancer lookup — accessible by the job's employer, the assigned freelancer, or an admin (jobs.read)
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/{id}/freelancer', [JobController::class, 'assignedFreelancer']);
    });
});

// Admin routes (for managing jobs, users, etc.) - can be protected with role-based middleware
Route::prefix('admin')->middleware(['auth:sanctum'])->group(function () {

    Route::get('/jobs', [JobController::class, 'index'])
        ->middleware(['permission:jobs.read']);

    Route::post('/jobs', [JobController::class, 'store'])
        ->middleware(['permission:jobs.create']);

    Route::patch('/jobs/{id}', [JobController::class, 'update'])
        ->middleware(['permission:jobs.update']);

    Route::delete('/jobs/{id}', [JobController::class, 'destroy'])
        ->middleware(['permission:jobs.delete']);

    Route::patch('/jobs/{id}/approve', [JobController::class, 'approve'])
        ->middleware(['permission:jobs.approve']);

    Route::patch('/jobs/{id}/assign', [JobController::class, 'assignFreelancer'])
        ->middleware(['permission:jobs.assign']);

    Route::patch('/employers/{employer}/approve', [EmployerController::class, 'approve'])
        ->middleware(['permission:employers.verify']);

    Route::patch('/employers/{employer}/revoke', [EmployerController::class, 'revokeVerification'])
        ->middleware(['permission:employers.verify']);

    Route::get('/performance', [AdminPerformanceController::class, 'index'])
        ->middleware(['permission:admin.dashboard']);
});

// Only Supper Admin can manage users roles and permissions
Route::middleware(['auth:sanctum', 'role:Super-Admin'])->group(function () {

    Route::get('/roles', [RolePermissionController::class, 'roles']);
    Route::post('/roles', [RolePermissionController::class, 'createRole']);
    Route::put('/roles/{id}', [RolePermissionController::class, 'updateRole']);
    Route::delete('/roles/{id}', [RolePermissionController::class, 'deleteRole']);

    Route::post('/roles/{id}/permissions', [RolePermissionController::class, 'syncRolePermissions']);

    Route::get('/permissions', [RolePermissionController::class, 'permissions']);
    Route::post('/permissions', [RolePermissionController::class, 'createPermission']);

    Route::post('/users/{id}/roles', [RolePermissionController::class, 'syncUserRoles']);
});

// User Managment
Route::middleware(['auth:sanctum', 'role:Super-Admin'])->group(function () {
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::post('/users', [UserController::class, 'store']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);

    Route::post('/users/{id}/roles', [UserController::class, 'syncRoles']);
});

