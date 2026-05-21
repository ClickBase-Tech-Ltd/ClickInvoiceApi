<!DOCTYPE html>
<html>
<head>
    <title>Verify Your ClickInvoice Account</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #2563eb; color: white; padding: 20px; text-align: center; }
        .content { background: #f9fafb; padding: 30px; border-radius: 8px; margin: 20px 0; }
        .otp-code {
            font-size: 32px;
            font-weight: bold;
            text-align: center;
            letter-spacing: 8px;
            color: #2563eb;
            margin: 20px 0;
        }
        .footer { text-align: center; color: #6b7280; font-size: 14px; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
       <img
                    src="https://app.clickinvoice.app/images/logo/logo.png"
                    alt="ClickInvoice"
                    width="140"
                >

        <div class="content">
            <h2>Hello {{ $firstName }} {{ $lastName }},</h2>

            <p>Welcome to ClickInvoice! To complete your registration and verify your email address, please use the following verification code:</p>

            <div class="otp-code">
                {{ $otp }}
            </div>

            <p><strong>This code will expire in 10 minutes.</strong></p>

            <p>Enter this code in the verification window to activate your account and start generating and sending professional invoices to your clients with ClickInvoice.</p>

            <p>If you didn't create an account with ClickInvoice, please ignore this email.</p>
        </div>

        @include('emails.partials.social-follow')
        <p style="color: #6b7280; font-size: 12px; margin-top: 12px;">
            <a href="https://clickinvoice.app" style="color:#0A66C2;">clickinvoice.app</a> ·
            <a href="mailto:support@clickinvoice.app" style="color:#0A66C2;">support@clickinvoice.app</a>
        </p>
        <p style="color: #9ca3af; font-size: 12px;">© {{ date('Y') }} ClickInvoice Ltd.</p>
    </div>
</body>
</html>
