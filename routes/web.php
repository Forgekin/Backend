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

// NOTE: the interactive Swagger UI page (/swagger) is registered in
// bootstrap/app.php so it runs without the stateful "web" middleware group
// (session/CSRF) — it's a static docs page and shouldn't need a DB session.
