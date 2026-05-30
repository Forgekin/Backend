@component('mail::message')
# Password Reset Request

Hello {{ $firstName ?? 'there' }},

We received a request to reset the password for your ForgeKin account. Click the button below to choose a new password:

@component('mail::button', ['url' => $url])
Reset Password
@endcomponent

This link will expire in 60 minutes.

If you didn't request a password reset, please ignore this email.
@endcomponent
