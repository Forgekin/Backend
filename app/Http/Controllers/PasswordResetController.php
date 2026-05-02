<?php

namespace App\Http\Controllers;

use App\Models\Freelancer;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Http\Requests\PasswordResetRequest;
use App\Http\Requests\NewPasswordRequest;
use App\Mail\FreelancerPasswordResetMail;
use Carbon\Carbon;

class PasswordResetController extends Controller
{
    /**
     * Forgot password
     *
     * Sends a password reset email with a tokenized link to the freelancer's registered email. The token expires in 1 hour. Rate limited to 3 requests per minute.
     *
     * @group Password Reset
     * @unauthenticated
     *
     * @bodyParam email string required The freelancer's registered email. Example: john@gmail.com
     *
     * @response 200 scenario="Sent" {"message":"Reset link sent","success":true}
     * @response 422 scenario="Email not found" {"message":"The selected email is invalid.","errors":{"email":["The selected email is invalid."]}}
     * @response 429 scenario="Rate limited" {"message":"Too Many Attempts."}
     * @response 500 scenario="Server error" {"message":"Failed to process request","success":false}
     */
    public function forgotPassword(PasswordResetRequest $request)
    {
        try {
            $email = strtolower(trim($request->email));

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

            $firstName = Freelancer::where('email', $email)->value('first_name');

            Mail::to($email)->send(new FreelancerPasswordResetMail($resetUrl, $firstName));

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

    /**
     * Reset password
     *
     * Resets the freelancer's password using a valid token from the reset email. Token must not be expired (1 hour TTL). Tokens are hashed — looked up by email and verified with Hash::check.
     *
     * @group Password Reset
     * @unauthenticated
     *
     * @bodyParam token string required The reset token from the email link. Example: aBcDeFgHiJkLmNoPqRsTuVwXyZ123456789012345678901234567890abcd
     * @bodyParam email string required The freelancer's email. Example: john@gmail.com
     * @bodyParam password string required New password (min 8 chars). Example: NewPassword1!
     * @bodyParam password_confirmation string required Must match password. Example: NewPassword1!
     *
     * @response 200 scenario="Success" {"message":"Password updated successfully","success":true}
     * @response 422 scenario="Invalid token" {"message":"Invalid or expired token","success":false}
     * @response 422 scenario="Expired token" {"message":"Token expired","success":false}
     * @response 422 scenario="Account not found" {"message":"Account not found","success":false}
     * @response 500 scenario="Server error" {"message":"Reset failed","success":false}
     */
    public function resetPassword(NewPasswordRequest $request)
    {
        try {
            $resetRecord = DB::table('freelancer_password_resets')
                ->where('email', strtolower(trim($request->email)))
                ->first();

            if (!$resetRecord || !Hash::check($request->token, $resetRecord->token)) {
                return response()->json([
                    'message' => 'Invalid or expired token',
                    'success' => false
                ], 422);
            }

            if (Carbon::parse($resetRecord->created_at)->addHours(1)->isPast()) {
                DB::table('freelancer_password_resets')
                    ->where('email', $resetRecord->email)
                    ->delete();

                return response()->json([
                    'message' => 'Token expired',
                    'success' => false
                ], 422);
            }

            $freelancer = Freelancer::where('email', $resetRecord->email)->first();

            if (!$freelancer) {
                return response()->json([
                    'message' => 'Account not found',
                    'success' => false
                ], 422);
            }

            $freelancer->password = Hash::make($request->password);
            $freelancer->save();

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
