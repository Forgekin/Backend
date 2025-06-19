<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\PasswordResetController;

// Route to display the password reset form
Route::get('/reset-password', function (Request $request) {
    $token = $request->query('token');
    return view('auth.reset-password', ['token' => $token]);
});

// Route to handle the password update form submission
Route::post('/reset-password', [PasswordResetController::class, 'resetPassword'])->name('password.update');
