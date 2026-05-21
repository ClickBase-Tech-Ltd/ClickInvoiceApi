{{-- resources/views/emails/single-customer.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject }}</title>

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
            border-bottom: 1px solid #e5e7eb;
        }

        /* Content */
        .content {
            padding: 28px 32px;
            font-size: 15px;
            line-height: 1.65;
            color: #374151;
        }

        .content p {
            margin: 0 0 16px;
        }

        .sender {
            margin-bottom: 20px;
            font-size: 14px;
            color: #6b7280;
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
            <td class="header" align="center" style="text-align: center;">
                <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
                    <tr>
                        <td align="center">
                            <img
                                src="https://app.clickinvoice.app/images/logo/logo.png"
                                alt="ClickInvoice"
                                width="140"
                                style="margin: 0 auto; display: block;"
                            >
                        </td>
                    </tr>
                </table>
            </td>
        </tr>

        <!-- Content -->
        <tr>
            <td class="content">
                <div class="sender">
                    Message from <strong>{{ $tenantName }}</strong>
                    ({{ $tenantEmail }})
                </div>

                <p>Dear {{ $customerName }},</p>

                {!! nl2br(e($emailMessage)) !!}

                <p>
                    Best regards,<br>
                    <strong>{{ $tenantName }}</strong>
                </p>
            </td>
        </tr>

        <!-- Footer -->
        <tr>
            <td class="footer">
                @include('emails.partials.social-follow')
                <p style="margin-top:12px;">
                    <a href="https://clickinvoice.app">clickinvoice.app</a> ·
                    <a href="mailto:support@clickinvoice.app">support@clickinvoice.app</a>
                </p>
                <p>© {{ date('Y') }} ClickInvoice Ltd.</p>
                <p>This is an automated message. Please do not reply.</p>
            </td>
        </tr>

    </table>
</center>
</body>
</html>
