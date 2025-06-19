<!DOCTYPE html>
<html>

<head>
    <title>Reset Password</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', sans-serif;
            background-color: #f4f6f9;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .container {
            width: 100%;
            max-width: 420px;
            background-color: #ffffff;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }

        h1 {
            text-align: center;
            color: #333;
            margin-bottom: 25px;
        }

        label {
            display: block;
            margin-top: 18px;
            font-size: 14px;
            color: #555;
        }

        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 12px 14px;
            margin-top: 6px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 14px;
        }

        input[type="hidden"] {
            display: none;
        }

        button {
            width: 100%;
            background-color: #3490dc;
            color: #ffffff;
            padding: 14px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            cursor: pointer;
            margin-top: 25px;
        }

        button:hover {
            background-color: #2779bd;
        }

        .footer {
            margin-top: 25px;
            text-align: center;
            font-size: 13px;
            color: #888;
        }

        @media (max-width: 480px) {
            .container {
                padding: 20px;
                border-radius: 6px;
            }

            h1 {
                font-size: 20px;
                margin-bottom: 18px;
            }

            button {
                padding: 12px;
                font-size: 15px;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>Reset Password</h1>

        <form method="POST" action="{{ route('password.update') }}">
            @csrf

            <!-- Hidden token input -->
            <input type="hidden" name="token" value="{{ $token }}">

            <label for="email">Email Address</label>
            <input type="email" name="email" required placeholder="Enter your email">

            <label for="password">New Password</label>
            <input type="password" name="password" required placeholder="New password">

            <label for="password_confirmation">Confirm Password</label>
            <input type="password" name="password_confirmation" required placeholder="Confirm new password">

            <button type="submit">Reset Password</button>
        </form>

        <div class="footer">
            &copy; {{ date('Y') }} Forgekin. All rights reserved.
        </div>
    </div>
</body>

</html>
