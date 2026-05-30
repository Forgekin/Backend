<?php

namespace App\Http\Controllers;

use App\Models\Employer;
use App\Models\Freelancer;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Http\Requests\PasswordResetRequest;
use App\Http\Requests\NewPasswordRequest;
use App\Mail\PasswordResetMail;
use Carbon\Carbon;

class PasswordResetController extends Controller
{
    /**
     * Account types that support password reset.
     *
     * Each entry maps an account "guard" to its Eloquent model and the table
     * where its reset tokens are stored. Add a new entry here (e.g. admin
     * users) to extend the flow to another account type — the rest of the
     * controller is generic.
     *
     * @return array<string, array{model: class-string, table: string}>
     */
    protected function guards(): array
    {
        return [
            'freelancer' => [
                'model' => Freelancer::class,
                'table' => 'freelancer_password_resets',
            ],
            'employer' => [
                'model' => Employer::class,
                'table' => 'employer_password_resets',
            ],
        ];
    }

    /**
     * Forgot password
     *
     * Sends a password reset email with a tokenized link to the account's
     * registered email. Works for any supported account type (freelancer or
     * employer); if the same email is registered as more than one type, each
     * matching account receives its own link. Tokens expire in 1 hour.
     * Rate limited to 3 requests per minute.
     *
     * @group Password Reset
     * @unauthenticated
     *
     * @bodyParam email string required The account's registered email. Example: john@gmail.com
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
            $sent = false;

            foreach ($this->guards() as $config) {
                /** @var \Illuminate\Database\Eloquent\Model|null $account */
                $account = $config['model']::where('email', $email)->first();

                if (!$account) {
                    continue;
                }

                $token = Str::random(60);

                DB::table($config['table'])->where('email', $email)->delete();
                DB::table($config['table'])->insert([
                    'email' => $email,
                    'token' => Hash::make($token),
                    'created_at' => Carbon::now(),
                ]);

                $resetUrl = rtrim(config('app.frontend_url'), '/') . '/reset-password?token=' . $token;

                Mail::to($email)->send(new PasswordResetMail($resetUrl, $account->first_name ?? null));

                $sent = true;
            }

            if (!$sent) {
                // Should not happen — the request validates that the email
                // belongs to a supported account — but guard against it anyway.
                return response()->json([
                    'message' => 'Failed to process request',
                    'success' => false,
                ], 422);
            }

            return response()->json([
                'message' => 'Reset link sent',
                'success' => true,
            ]);

        } catch (\Exception $e) {
            Log::error('Forgot password error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to process request',
                'success' => false,
            ], 500);
        }
    }

    /**
     * Reset password
     *
     * Resets an account's password using a valid token from the reset email.
     * The correct account type is resolved automatically by matching the token
     * against each supported account's reset table, so the client only needs to
     * send the email, token, and new password. Token must not be expired
     * (1 hour TTL). Tokens are hashed and verified with Hash::check.
     *
     * @group Password Reset
     * @unauthenticated
     *
     * @bodyParam token string required The reset token from the email link. Example: aBcDeFgHiJkLmNoPqRsTuVwXyZ123456789012345678901234567890abcd
     * @bodyParam email string required The account's email. Example: john@gmail.com
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
            $email = strtolower(trim($request->email));

            foreach ($this->guards() as $config) {
                $record = DB::table($config['table'])->where('email', $email)->first();

                // The token only matches in the table of the account type it was
                // issued for — this is how we resolve which account to reset.
                if (!$record || !Hash::check($request->token, $record->token)) {
                    continue;
                }

                if (Carbon::parse($record->created_at)->addHours(1)->isPast()) {
                    DB::table($config['table'])->where('email', $email)->delete();

                    return response()->json([
                        'message' => 'Token expired',
                        'success' => false,
                    ], 422);
                }

                /** @var \Illuminate\Database\Eloquent\Model|null $account */
                $account = $config['model']::where('email', $email)->first();

                if (!$account) {
                    return response()->json([
                        'message' => 'Account not found',
                        'success' => false,
                    ], 422);
                }

                $account->password = Hash::make($request->password);
                $account->save();

                DB::table($config['table'])->where('email', $email)->delete();

                return response()->json([
                    'message' => 'Password updated successfully',
                    'success' => true,
                ]);
            }

            return response()->json([
                'message' => 'Invalid or expired token',
                'success' => false,
            ], 422);

        } catch (\Exception $e) {
            Log::error('Reset password error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Reset failed',
                'success' => false,
            ], 500);
        }
    }
}
