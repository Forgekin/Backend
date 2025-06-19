<?php

namespace App\Http\Controllers;

use App\Models\Freelancer;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
//use App\Http\Controllers\Controller;
use App\Http\Requests\PasswordResetRequest;
use App\Http\Requests\NewPasswordRequest;
use App\Mail\FreelancerPasswordResetMail;
use Carbon\Carbon;

class PasswordResetController extends Controller
{
    public function __construct()
    {
        $this->middleware('guest:api'); // Ensure no auth is required
    }

    public function forgotPassword(PasswordResetRequest $request)
    {
        try {
            $email = strtolower(trim($request->email));

            // Delete existing tokens
            DB::table('freelancer_password_resets')
                ->where('email', $email)
                ->delete();

            $token = Str::random(60);

            DB::table('freelancer_password_resets')->insert([
                'email' => $email,
                'token' => Hash::make($token), // Hash the token for storage
                'created_at' => Carbon::now()
            ]);

            $freelancer = Freelancer::where('email', $email)->first();
            Mail::to($email)->send(new FreelancerPasswordResetMail($token));

            return response()->json([
                'message' => 'Reset link sent',
                'success' => true
            ]);

        } catch (\Exception $e) {
            \Log::error('Password reset error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to process request',
                'success' => false,
                'error' => $e->getMessage() // optional: return error in API response for debugging
            ], 500);
        }
    }

    public function resetPassword(NewPasswordRequest $request)
    {
        try {
            $resetRecord = DB::table('freelancer_password_resets')
                ->where('email', $request->email)
                ->first();

            if (!$resetRecord || !Hash::check($request->token, $resetRecord->token)) {
                return response()->json([
                    'message' => 'Invalid token',
                    'success' => false
                ], 422);
            }

            if (Carbon::parse($resetRecord->created_at)->addHours(1)->isPast()) {
                return response()->json([
                    'message' => 'Token expired',
                    'success' => false
                ], 422);
            }

            $freelancer = Freelancer::where('email', $request->email)->first();
            $freelancer->password = Hash::make($request->password);
            $freelancer->save();

            DB::table('freelancer_password_resets')
                ->where('email', $request->email)
                ->delete();

            return response()->json([
                'message' => 'Password updated',
                'success' => true
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Reset failed',
                'success' => false
            ], 500);
        }
    }
}
