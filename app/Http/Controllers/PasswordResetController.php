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
    public function forgotPassword(PasswordResetRequest $request)
    {
        try {
            $email = strtolower(trim($request->email));

            // Delete existing tokens for this email
            DB::table('freelancer_password_resets')
                ->where('email', $email)
                ->delete();

            $token = Str::random(60);

            DB::table('freelancer_password_resets')->insert([
                'email' => $email,
                'token' => Hash::make($token),
                'created_at' => Carbon::now()
            ]);

            $resetUrl = config('app.frontend_url') . '/reset-password?token=' . $token;

            Mail::to($email)->send(new FreelancerPasswordResetMail($resetUrl));

            return response()->json([
                'message' => 'Reset link sent',
                'success' => true
            ]);

        } catch (\Exception $e) {
            \Log::error('Forgot password error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to process request',
                'success' => false
            ], 500);
        }
    }


    public function resetPassword(NewPasswordRequest $request)
    {
        try {
            // Look up by email since token is hashed
            $resetRecord = DB::table('freelancer_password_resets')
                ->where('email', strtolower(trim($request->email)))
                ->first();

            if (!$resetRecord || !Hash::check($request->token, $resetRecord->token)) {
                return response()->json([
                    'message' => 'Invalid or expired token',
                    'success' => false
                ], 422);
            }

            // Check token expiry
            if (Carbon::parse($resetRecord->created_at)->addHours(1)->isPast()) {
                DB::table('freelancer_password_resets')
                    ->where('email', $resetRecord->email)
                    ->delete();

                return response()->json([
                    'message' => 'Token expired',
                    'success' => false
                ], 422);
            }

            // Get freelancer via email from reset record
            $freelancer = Freelancer::where('email', $resetRecord->email)->first();

            if (!$freelancer) {
                return response()->json([
                    'message' => 'Account not found',
                    'success' => false
                ], 422);
            }

            // Update password
            $freelancer->password = Hash::make($request->password);
            $freelancer->save();

            // Clean up reset token
            DB::table('freelancer_password_resets')
                ->where('email', $resetRecord->email)
                ->delete();

            return response()->json([
                'message' => 'Password updated successfully',
                'success' => true
            ]);

        } catch (\Exception $e) {
            \Log::error('Reset password error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Reset failed',
                'success' => false
            ], 500);
        }
    }


}
