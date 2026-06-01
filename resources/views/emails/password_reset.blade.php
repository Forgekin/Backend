<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset</title>
</head>
<body style="margin:0; padding:0; background-color:#f1ecca; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f1ecca; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px; width:100%;">
                    <!-- Brand bar -->
                    <tr>
                        <td align="center" style="padding:0 0 20px 0;">
                            <img src="{{ $message->embed(public_path('email/forgekin-logo.png')) }}" alt="{{ config('app.name') }}" width="170" style="display:inline-block; height:auto; border:0;">
                        </td>
                    </tr>

                    <!-- Card -->
                    <tr>
                        <td style="background-color:#ffffff; border-radius:12px; border-top:4px solid #19a24b; box-shadow:0 2px 8px rgba(25,162,75,0.08); padding:36px;">
                            <h1 style="margin:0 0 16px 0; color:#19a24b; font-size:22px; font-weight:bold;">
                                Password reset request
                            </h1>

                            <p style="margin:0 0 16px 0; font-size:16px; line-height:1.6; color:#1f2937;">
                                Hello {{ $firstName ?? 'there' }},
                            </p>

                            <p style="margin:0 0 24px 0; font-size:16px; line-height:1.6; color:#1f2937;">
                                We received a request to reset the password for your {{ config('app.name') }} account.
                                Click the button below to choose a new password.
                            </p>

                            <!-- Button -->
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px 0;">
                                <tr>
                                    <td align="center">
                                        <a href="{{ $url }}" target="_blank"
                                           style="display:inline-block; background-color:#e7a418; color:#ffffff; text-decoration:none; font-weight:bold; font-size:16px; padding:14px 36px; border-radius:8px;">
                                            Reset Password
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 8px 0; font-size:14px; line-height:1.6; color:#6b7280;">
                                This link will expire in 60 minutes.
                            </p>
                            <p style="margin:0 0 24px 0; font-size:14px; line-height:1.6; color:#6b7280;">
                                If you didn't request a password reset, you can safely ignore this email.
                            </p>

                            <!-- Fallback URL -->
                            <p style="margin:0 0 6px 0; font-size:13px; color:#9ca3af;">
                                If the button doesn't work, copy and paste this link into your browser:
                            </p>
                            <p style="margin:0; font-size:13px; word-break:break-all;">
                                <a href="{{ $url }}" style="color:#e7a418;">{{ $url }}</a>
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td align="center" style="padding:24px 16px 0 16px;">
                            <p style="margin:0; font-size:12px; color:#8b8568;">
                                &copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
