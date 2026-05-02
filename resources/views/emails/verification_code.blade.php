<!DOCTYPE html>
<html>
<head>
    <title>Email Verification</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .code { 
            font-size: 24px; 
            font-weight: bold; 
            color: #2563eb; 
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Verify Your Email Address</h1>
        <p>Hello {{ $firstName ?? 'there' }},</p>
        <p>Please use the following verification code to confirm your email address:</p>
        
        <div class="code">{{ $code }}</div>
        
        <p>This code will expire in 15 minutes.</p>
        <p>If you didn't request this, please ignore this email.</p>
        
        <p>Thanks,<br>{{ config('app.name') }}</p>
    </div>
</body>
</html>