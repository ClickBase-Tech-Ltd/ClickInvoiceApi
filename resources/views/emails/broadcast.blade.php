{{-- resources/views/emails/broadcast.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject ?? 'Broadcast Message' }}</title>

    <style type="text/css">
        body {
            margin: 0;
            padding: 0;
            background-color: #f6f9fc;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI",
                         Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #1f2937;
        }

        table {
            border-spacing: 0;
            width: 100%;
        }

        img {
            border: 0;
            display: block;
        }

        .wrapper {
            width: 100%;
            padding: 24px 0;
            background-color: #f6f9fc;
        }

        .main {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 6px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            overflow: hidden;
        }

        /* Header Logo */
        .header {
            padding: 24px;
            text-align: center;
            border-bottom: 1px solid #e5e7eb;
        }

        .header .logo {
            display: inline-block;
            margin: 0 auto;
        }

        /* Content */
        .content {
            padding: 28px 32px;
            font-size: 15px;
            line-height: 1.65;
            color: #374151;
        }

        .content p {
            margin-bottom: 16px;
        }

        /* Button */
        .btn-container {
            text-align: center;
            margin-top: 16px;
        }

        .btn {
            display: inline-block;
            padding: 12px 24px;
            background-color: #0A66C2;
            color: #ffffff;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            border-radius: 4px;
        }

        /* Footer */
        .footer {
            padding: 16px 24px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            font-size: 12px;
            color: #6b7280;
        }

        .footer a {
            color: #6b7280;
            text-decoration: none;
        }

        .footer p {
            margin: 4px 0;
        }

        @media screen and (max-width: 600px) {
            .content {
                padding: 24px 20px;
            }
            .header {
                padding: 20px 16px;
            }
            .footer {
                padding: 12px 16px;
            }
        }
    </style>
</head>
<body>
<center class="wrapper">
    <table class="main">

        <!-- Header Logo -->
        <tr>
            <td class="header">
                <div class="logo">
                    <img
                        src="https://app.clickinvoice.app/images/logo/logo.svg"
                        alt="ClickInvoice"
                        width="140"
                    >
                </div>
            </td>
        </tr>

        <!-- Content -->
        <tr>
            <td class="content">
                <p>Dear Customer,</p>

                {!! nl2br(e($emailMessage)) !!}

                <!--<div class="btn-container">
                    <a href="https://app.clickinvoice.app/signin/" class="btn">
                        Go to ClickInvoice
                    </a>
                </div>-->

                <p>Best regards,<br>
                <strong>{{ $tenantName }}</strong></p>
            </td>
        </tr>

        <!-- Footer -->
        <tr>
            <td class="footer">
                <p>
                    You are receiving this because you are a registered customer under {{ $tenantName }} on ClickInvoice.
                </p>
                <p>
                    <a href="https://clickinvoice.app">clickinvoice.app</a> ·
                    <a href="mailto:info@clickinvoice.app">info@clickinvoice.app</a>
                </p>
                <p>© {{ date('Y') }} ClickInvoice</p>
            </td>
        </tr>

    </table>
</center>
</body>
</html>
