<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FreelancerController;
use App\Http\Controllers\PasswordResetController;


// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

Route::prefix('freelancers')->group(function () {
    // Public routes
    Route::post('/', [FreelancerController::class, 'store']);
    Route::post('/verify-email', [FreelancerController::class, 'verifyEmail']);
    Route::post('/resend-verification', [FreelancerController::class, 'resendVerificationCode']);
    Route::post('/login', [FreelancerController::class, 'login']);
    Route::get('/', [FreelancerController::class, 'index']);
    Route::get('/{freelancer}', [FreelancerController::class, 'show']);

    // Protected routes (require auth)
    Route::middleware(['auth:sanctum', 'verified'])->group(function () {
        Route::put('/{freelancer}', [FreelancerController::class, 'update']);
        Route::delete('/{freelancer}', [FreelancerController::class, 'destroy']);
        Route::post('/logout', [FreelancerController::class, 'logout']);
    });
});

// Forgot Password and Reset Password routes
Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
// Route::post('/reset-password', [PasswordResetController::class, 'resetPassword'])->name('password.update');

