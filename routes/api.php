<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FreelancerController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\EmployerController;
use App\Http\Controllers\JobController;


// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');


// Freelancer routes
Route::prefix('freelancers')->group(function () {
    // Public routes
    Route::post('/', [FreelancerController::class, 'store']);
    Route::post('/verify-email', [FreelancerController::class, 'verifyEmail']);
    Route::post('/resend-verification', [FreelancerController::class, 'resendVerificationCode']);
    Route::post('/login', [FreelancerController::class, 'login']);
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
    });
});

// Forgot Password and Reset Password routes
Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
// Route::post('/reset-password', [PasswordResetController::class, 'resetPassword'])->name('password.update');


// Employer routes
Route::prefix('employers')->group(function () {

    // Public routes
    Route::post('/register', [EmployerController::class, 'register']);
    Route::post('/login', [EmployerController::class, 'login']);
    Route::get('/', [EmployerController::class, 'index']);
    Route::get('/{employer}', [EmployerController::class, 'show']);

    // Protected routes (require auth)
    Route::middleware(['auth:sanctum', 'verified'])->group(function () {
        Route::put('/{employer}', [EmployerController::class, 'update']);
        Route::delete('/{employer}', [EmployerController::class, 'destroy']);
        Route::post('/logout', [EmployerController::class, 'logout']);
    });
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
});
