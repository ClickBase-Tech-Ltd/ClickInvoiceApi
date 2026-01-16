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
            background-color: #f8fafc;
            font-family: 'Roboto', 'Helvetica Neue', Arial, sans-serif;
        }
        table {
            border-spacing: 0;
        }
        td {
            padding: 0;
        }
        img {
            border: 0;
            display: block;
        }
        .wrapper {
            width: 100%;
            table-layout: fixed;
            background-color: #f8fafc;
            padding: 20px 0;
        }
        .main {
            width: 100%;
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            overflow: hidden;
            box-shadow: 0 6px 20px rgba(0,0,0,0.05);
        }
        .header {
            background-color: #0A66C2;
            padding: 25px 20px 15px 20px;
            text-align: center;
            color: #ffffff;
        }
        .header img {
            margin: 0 auto 10px;
        }
        .header .subject {
            font-size: 20px;
            font-weight: 500; /* Semi-bold, not too heavy */
            margin: 0;
            padding: 0 15px; /* horizontal padding */
            line-height: 1.4;
        }
        .content {
            padding: 25px 30px;
            color: #334155;
            font-size: 16px;
            line-height: 1.6;
        }
        .message {
            background-color: #f0f9ff;
            padding: 20px;
            border-radius: 6px;
            margin: 15px 0;
            font-size: 16px;
        }
        .btn-container {
            text-align: center;
            margin: 20px 0;
        }
        .btn {
            display: inline-block;
            padding: 14px 32px;
            background-color: #0A66C2;
            color: #ffffff;
            text-decoration: none;
            font-weight: 600;
            font-size: 16px;
            border-radius: 6px;
            box-shadow: 0 3px 10px rgba(10,102,194,0.25);
        }
        .footer {
            background-color: #0A66C2;
            color: #ffffff;
            padding: 12px 20px;
            text-align: center;
            font-size: 13px;
        }
        .tagline {
            font-size: 14px;
            font-weight: 600;
            margin: 5px 0 8px;
        }
        .social {
            margin: 8px 0;
        }
        .social a {
            margin: 0 8px;
            display: inline-block;
        }
        .social img {
            width: 28px;
            height: 28px;
        }
        .links a {
            color: #ffffff;
            text-decoration: none;
            margin: 0 5px;
            font-size: 12px;
        }
        @media screen and (max-width: 600px) {
            .content {
                padding: 20px 20px;
            }
            .header {
                padding: 20px 15px 12px 15px;
            }
            .footer {
                padding: 10px 15px;
            }
        }
    </style>
</head>
<body>
    <center class="wrapper">
        <table class="main" width="100%">
            <!-- Header -->
            <tr>
                <td class="header">
                    <img src="https://app.clickinvoice.app/images/logo/logo-dark.png" alt="ClickInvoice" width="150" height="auto">
                    <p class="subject">{{ $subjectLine ?? 'Notification' }}</p>
                </td>
            </tr>

            <!-- Content -->
            <tr>
                <td class="content">
                    <div class="message">
                        {!! nl2br(e($messageBody)) !!}
                    </div>

                    <div class="btn-container">
                        <a href="https://app.clickinvoice.app/signin/" class="btn">Manage Your Business</a>
                    </div>
                </td>
            </tr>

            <!-- Footer -->
            <tr>
                <td class="footer">
                    <p class="tagline">Smart Invoicing. Get Paid Faster.</p>

                    <div class="social">
                        <a href="https://www.linkedin.com/company/110655244/" target="_blank">
                            <img src="https://img.icons8.com/color/48/linkedin.png" alt="LinkedIn">
                        </a>
                        <a href="https://web.facebook.com/invoiceclick" target="_blank">
                            <img src="https://img.icons8.com/color/48/facebook.png" alt="Facebook">
                        </a>
                    </div>

                    <p>
                        <a href="https://clickinvoice.app" style="color:#ffffff; text-decoration:none;">clickinvoice.app</a> |
                        <a href="mailto:info@clickinvoice.app" style="color:#ffffff; text-decoration:none;">info@clickinvoice.app</a>
                    </p>

                    <p>© {{ date('Y') }} ClickInvoice. All rights reserved.</p>
                </td>
            </tr>
        </table>
    </center>
</body>
</html>
