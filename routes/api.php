<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminPerformanceController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\FreelancerController;
use App\Http\Controllers\FreelancerDashboardController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\EmployerController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Controllers\UserController;


// Public contact form — sends an email to the support inbox over SMTP.
Route::post('/contact', [ContactController::class, 'send'])->middleware('throttle:5,1');

// In-app notifications — for any authenticated account (freelancer or employer).
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);

    // Internal support request — any signed-in user can message the support
    // team (Super-Admins / Admins), who receive it in their Notification Center.
    Route::post('/support/messages', [ContactController::class, 'support'])->middleware('throttle:10,1');
});

// Freelancer routes
Route::prefix('freelancers')->group(function () {
    // Public routes
    Route::post('/', [FreelancerController::class, 'store']);
    Route::post('/verify-email', [FreelancerController::class, 'verifyEmail']);
    Route::post('/resend-verification', [FreelancerController::class, 'resendVerificationCode']);
    Route::post('/login', [FreelancerController::class, 'login'])->middleware('throttle:5,1');
    Route::get('/', action: [FreelancerController::class, 'index']);
    Route::get('/{freelancer}', [FreelancerController::class, 'show']);

    // Resume — accessible to the freelancer themselves, employers who have this
    // freelancer assigned to one of their jobs, or admins with jobs.read.
    Route::middleware('auth:sanctum')->get('/{freelancer}/resume', [FreelancerController::class, 'resume']);

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

// Authenticated admin self-service profile (no special permission — manage your own account)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/profile', [UserController::class, 'me']);
    Route::put('/profile', [UserController::class, 'updateProfile']);
    Route::put('/profile/password', [UserController::class, 'changePassword']);
    Route::post('/profile/avatar', [UserController::class, 'updateAvatar']);
    Route::delete('/profile/avatar', [UserController::class, 'removeAvatar']);
});



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

    Route::patch('/jobs/{id}/unapprove', [JobController::class, 'unapprove'])
        ->middleware(['permission:jobs.approve']);

    Route::patch('/jobs/{id}/reject', [JobController::class, 'reject'])
        ->middleware(['permission:jobs.reject']);

    Route::patch('/jobs/{id}/assign', [JobController::class, 'assignFreelancer'])
        ->middleware(['permission:jobs.assign']);

    Route::patch('/jobs/{id}/unassign', [JobController::class, 'unassignFreelancer'])
        ->middleware(['permission:jobs.assign']);

    Route::patch('/jobs/{id}/status', [JobController::class, 'updateStatus'])
        ->middleware(['permission:jobs.assign']);

    Route::patch('/employers/{employer}/approve', [EmployerController::class, 'approve'])
        ->middleware(['permission:employers.verify']);

    Route::patch('/employers/{employer}/revoke', [EmployerController::class, 'revokeVerification'])
        ->middleware(['permission:employers.verify']);

    Route::patch('/freelancers/{freelancer}/deactivate', [FreelancerController::class, 'deactivate'])
        ->middleware(['role:Super-Admin|Admin']);

    Route::patch('/freelancers/{freelancer}/reactivate', [FreelancerController::class, 'reactivate'])
        ->middleware(['role:Super-Admin|Admin']);

    // Manage general details of a freelancer / employer
    Route::patch('/freelancers/{freelancer}', [FreelancerController::class, 'adminUpdate'])
        ->middleware(['role:Super-Admin|Admin']);

    Route::patch('/employers/{employer}', [EmployerController::class, 'adminUpdate'])
        ->middleware(['role:Super-Admin|Admin']);

    Route::get('/performance', [AdminPerformanceController::class, 'index'])
        ->middleware(['permission:admin.dashboard']);

    // Support inbox — public "Contact Us" submissions, for the admin
    // Support & Notification Center. Open a message and reply to the sender.
    Route::get('/contact-messages', [ContactController::class, 'index'])
        ->middleware(['role:Super-Admin|Admin']);

    Route::get('/contact-messages/{id}', [ContactController::class, 'show'])
        ->middleware(['role:Super-Admin|Admin']);

    Route::post('/contact-messages/{id}/reply', [ContactController::class, 'reply'])
        ->middleware(['role:Super-Admin|Admin']);
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
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);
    Route::patch('/users/{id}/deactivate', [UserController::class, 'deactivate']);
    Route::patch('/users/{id}/reactivate', [UserController::class, 'reactivate']);
    Route::post('/users/{id}/avatar', [UserController::class, 'uploadUserAvatar']);
    Route::delete('/users/{id}/avatar', [UserController::class, 'removeUserAvatar']);

    Route::post('/users/{id}/roles', [UserController::class, 'syncRoles']);
});

