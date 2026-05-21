<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Welcome to {{ $appName }}</title>

  <style type="text/css">
    body { margin: 0; padding: 0; background-color: #f6f9fc; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; color: #1f2937; }
    table { border-spacing: 0; width: 100%; }
    img { border: 0; display: block; }
    .wrapper { width: 100%; padding: 24px 0; background-color: #f6f9fc; }
    .main { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 6px; box-shadow: 0 4px 16px rgba(0,0,0,0.04); overflow: hidden; }
    .header { padding: 24px; text-align: center; border-bottom: 1px solid #e5e7eb; }
    .content { padding: 28px 32px; font-size: 15px; line-height: 1.65; color: #374151; }
    .content p { margin-bottom: 16px; }
    .btn-container { text-align: center; margin-top: 16px; }
    .btn { display: inline-block; padding: 12px 24px; background-color: #0077B5; color: #ffffff; text-decoration: none; font-size: 14px; font-weight: 500; border-radius: 4px; }
    .footer { padding: 16px 24px; border-top: 1px solid #e5e7eb; text-align: center; font-size: 12px; color: #6b7280; }
    .footer a { color: #6b7280; text-decoration: none; }
    @media screen and (max-width: 600px) { .content { padding: 24px 20px; } .header { padding: 20px 16px; } .footer { padding: 12px 16px; } }
  </style>
</head>
<body>
<center class="wrapper">
  <table class="main">
    <tr>
      <td class="header">
        <div class="logo">
          <img src="https://app.clickinvoice.app/images/logo/logo.svg" alt="ClickInvoice" width="140">
        </div>
      </td>
    </tr>

    <tr>
      <td class="content">
                <h2 style="color:#0A66C2; margin-top:0;">Welcome to {{ $appName }}</h2>

        <p>Dear {{ $user->name ?? 'there' }},</p>

        <p>Your account has been successfully activated. You can now sign in to your account using your credentials.</p>

        <div class="btn-container">
          <a href="https://app.clickinvoice.app/" class="btn">Sign in to your account</a>
        </div>

        <p style="margin-top:18px;">If you have any questions, reply to this email or contact our support team.</p>

        <p style="margin-top:18px">Best regards,<br><strong>The {{ $appName }} Team</strong></p>
      </td>
    </tr>

    <tr>
      <td class="footer">
        <p>You are receiving this because you (or someone using this email) created an account on {{ $appName }}.</p>
        @include('emails.partials.social-follow')
        <p style="margin-top:12px;"><a href="https://clickinvoice.app">clickinvoice.app</a> · <a href="mailto:support@clickinvoice.app">support@clickinvoice.app</a></p>
        <p>© {{ date('Y') }} ClickInvoice Ltd.</p>
      </td>
    </tr>
  </table>
</center>
</body>
</html>
