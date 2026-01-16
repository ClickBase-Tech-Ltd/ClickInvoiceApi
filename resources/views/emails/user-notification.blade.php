{{-- resources/views/emails/user-notification.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subjectLine ?? 'Notification' }}</title>

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
            padding: 0; /* Removed top and bottom padding */
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

        /* Header */
        .header {
            padding: 24px 24px 16px;
            text-align: center;
            border-bottom: 1px solid #e5e7eb;
        }

        .header img {
            margin: 0 auto;
        }

        /* Content */
        .content {
            padding: 28px 32px;
            font-size: 15px;
            line-height: 1.65;
            color: #374151;
        }

        .message {
            margin-bottom: 24px;
        }

        /* Button */
        .btn-container {
            margin-top: 8px;
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
                padding: 20px 16px 14px;
            }

            .footer {
                padding: 14px 16px;
            }
        }
    </style>
</head>
<body>
<center class="wrapper">
    <table class="main">

        <!-- Header -->
        <tr>
            <td class="header">
                <img
                    src="https://app.clickinvoice.app/images/logo/logo.png"
                    alt="ClickInvoice"
                    width="140"
                >
            </td>
        </tr>

        <!-- Content -->
        <tr>
            <td class="content" style="padding-left:32px; padding-right:32px;">
                <div class="message">
                    {!! nl2br(e($messageBody)) !!}
                </div>

                <div class="btn-container">
                    <a href="https://app.clickinvoice.app/signin/" class="btn">
                        Go to ClickInvoice
                    </a>
                </div>
            </td>
        </tr>

        <!-- Footer -->
        <tr>
            <td class="footer">
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
