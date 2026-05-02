<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification</title>
</head>
<body style="margin:0; padding:0; background-color:#f1ecca; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f1ecca; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px; width:100%;">
                    <!-- Brand bar -->
                    <tr>
                        <td align="center" style="padding:0 0 20px 0;">
                            <span style="font-size:20px; font-weight:bold; color:#19a24b; letter-spacing:0.3px;">
                                {{ config('app.name') }}
                            </span>
                        </td>
                    </tr>

                    <!-- Card -->
                    <tr>
                        <td style="background-color:#ffffff; border-radius:12px; border-top:4px solid #19a24b; box-shadow:0 2px 8px rgba(25,162,75,0.08); padding:36px;">
                            <h1 style="margin:0 0 16px 0; color:#19a24b; font-size:22px; font-weight:bold;">
                                Verify your email address
                            </h1>

                            <p style="margin:0 0 16px 0; font-size:16px; line-height:1.6; color:#1f2937;">
                                Hello {{ $firstName ?? 'there' }},
                            </p>

                            <p style="margin:0 0 24px 0; font-size:16px; line-height:1.6; color:#1f2937;">
                                Use the verification code below to confirm your email address and activate your {{ config('app.name') }} account.
                            </p>

                            <!-- Code callout -->
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px 0;">
                                <tr>
                                    <td align="center" style="background-color:#fdf6e3; border:1px dashed #e7a418; border-radius:10px; padding:24px;">
                                        <div style="font-size:13px; color:#8b8568; letter-spacing:1px; text-transform:uppercase; margin-bottom:8px;">
                                            Your verification code
                                        </div>
                                        <div style="font-size:32px; font-weight:bold; color:#e7a418; letter-spacing:6px; font-family:'Courier New',Courier,monospace;">
                                            {{ $code }}
                                        </div>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 8px 0; font-size:14px; line-height:1.6; color:#6b7280;">
                                This code will expire in 30 minutes.
                            </p>
                            <p style="margin:0 0 24px 0; font-size:14px; line-height:1.6; color:#6b7280;">
                                If you didn't request this, you can safely ignore this email.
                            </p>

                            <p style="margin:0; font-size:16px; color:#1f2937;">
                                Thanks,<br>
                                <span style="color:#19a24b; font-weight:600;">{{ config('app.name') }}</span>
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
