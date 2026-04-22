<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\PasswordResetController;

// Route::get('/reset-password', function (Request $request) {
//     $token = $request->query('token');  // This should be only the token string
//     return view('auth.reset-password', ['token' => $token]);
// });

Route::get('/reset-password', function (Request $request) {
    $token = $request->query('token');
    \Log::info('Token from query string: ' . $token);
    return view('auth.reset-password', ['token' => $token]);
});


Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);
