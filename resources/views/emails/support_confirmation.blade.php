{{-- resources/views/emails/support_confirmation.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>We've Received Your Support Request</title>

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
        .btn { display: inline-block; padding: 12px 24px; background-color: #0A66C2; color: #ffffff; text-decoration: none; font-size: 14px; font-weight: 500; border-radius: 4px; }
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
                <h2 style="color:#0A66C2; margin-top:0;">We've Received Your Support Request</h2>

                <p>Dear {{ ucwords(strtolower($user->name ?? $ticket->user->name ?? 'Customer')) }},</p>
                <p>Thank you for reaching out! Your support ticket has been successfully created and our team is already looking into it.</p>

                <div style="background:#f0f9ff;padding:16px;border-left:4px solid #0A66C2;border-radius:6px;margin:16px 0;">
                    <p style="margin:0 0 8px 0;"><strong>Ticket Details:</strong></p>
                    <p style="margin:0;"><strong>Ticket ID:</strong> #{{ $ticket->ticketId ?? 'N/A' }}</p>
                    <p style="margin:0;"><strong>Subject:</strong> {{ $ticket->subject ?? 'No subject' }}</p>

                    @if(isset($ticket->created_at))
                        @php
                            $createdAt = $ticket->created_at;
                            try {
                                if (is_string($createdAt)) {
                                    $createdAt = \Carbon\Carbon::parse($createdAt);
                                } elseif (!($createdAt instanceof \Carbon\Carbon) && is_object($createdAt) && method_exists($createdAt, 'format')) {
                                    // assume it's a date-like object; keep as-is
                                } else {
                                    $createdAt = null;
                                }
                            } catch (\Exception $e) {
                                $createdAt = null;
                            }
                        @endphp
                        <p style="margin:0;"><strong>Submitted on:</strong> {{ $createdAt ? $createdAt->format('M d, Y \a\t h:i A') : 'N/A' }}</p>
                    @else
                        <p style="margin:0;"><strong>Submitted on:</strong> N/A</p>
                    @endif
                </div>

                <div class="btn-container">
                    <a href="https://app.clickinvoice.app/" class="btn">View Support Dashboard</a>
                </div>

                <p style="margin-top:18px;">We typically respond within <strong>24 hours</strong>. You'll get an email notification when we reply.<br>Best regards,<br><strong>ClickInvoice Support</strong></p>
            </td>
        </tr>

        <tr>
            <td class="footer">
                <p>You are receiving this because you submitted a support request on ClickInvoice.</p>
                @include('emails.partials.social-follow')
                <p style="margin-top:12px;"><a href="https://clickinvoice.app">clickinvoice.app</a> · <a href="mailto:support@clickinvoice.app">support@clickinvoice.app</a></p>
                <p>© {{ date('Y') }} ClickInvoice Ltd.</p>
            </td>
        </tr>
    </table>
</center>
</body>
</html>