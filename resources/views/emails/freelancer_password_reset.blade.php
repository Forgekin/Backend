@component('mail::message')
# Password Reset Request

Click the button below to reset your password:

@component('mail::button', ['url' => $url])
Reset Password
@endcomponent

This link will expire in 60 minutes.

If you didn't request a password reset, please ignore this email.
@endcomponent
