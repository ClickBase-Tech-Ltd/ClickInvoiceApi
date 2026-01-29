<!DOCTYPE html>
<html>
<head>
    <title>Your ClickInvoice Verification Code</title>
</head>
<body>
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <img
                    src="https://app.clickinvoice.app/images/logo/logo.png"
                    alt="ClickInvoice"
                    width="140"
                >

        <h2 style="color: #0A66C2;">ClickInvoice - Email Verification</h2>

        <p>Hello,</p>

        <p>Thank you for signing up with ClickInvoice! Use the verification code below to complete your registration:</p>

        <div style="background-color: #f3f4f6; padding: 20px; text-align: center; margin: 20px 0; border-radius: 8px;">
            <h1 style="color: #2563eb; font-size: 32px; letter-spacing: 8px; margin: 0;">{{ $otp }}</h1>
        </div>

        <p><strong>This code will expire in {{ $expires_in }}.</strong></p>

        <p>If you didn't request this code, please ignore this email.</p>

        <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 20px 0;">

        <p style="color: #6b7280; font-size: 14px;">
            Best regards,<br>
            The ClickInvoice Team
        </p>
    </div>
      <tr>
            <td class="footer">
                <p>
                    <a href="https://clickinvoice.app">clickinvoice.app</a> ·
                    <a href="mailto:info@clickinvoice.app">info@clickinvoice.app</a>
                </p>
                <p>© {{ date('Y') }} ClickInvoice Ltd.</p>
            </td>
        </tr>
</body>
</html>
