<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice approval code</title>
</head>
<body>
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <img src="https://app.clickinvoice.app/images/logo/logo.png" alt="ClickInvoice" width="140">

    <h2 style="color: #0A66C2;">Approval needed — invoice change</h2>

    <p>Hello {{ $ownerName }},</p>

    <p>
        <strong>{{ $actorName }}</strong>
        @if(!empty($actorEmail))
            ({{ $actorEmail }})
        @endif
        requested permission to <strong>void or edit line items</strong> on an invoice for
        <strong>{{ $tenantName }}</strong>.
    </p>

    <p>Invoice reference: <strong>{{ $invoiceLabel }}</strong></p>

    <p>Share this code only if you approve this action:</p>

    <div style="background-color: #f3f4f6; padding: 20px; text-align: center; margin: 20px 0; border-radius: 8px;">
        <h1 style="color: #0A66C2; font-size: 32px; letter-spacing: 8px; margin: 0;">{{ $otp }}</h1>
    </div>

    <p><strong>This code expires in {{ $expires_in }}.</strong></p>

    <p style="color: #6b7280; font-size: 14px;">If you did not expect this, secure your account and ignore this email.</p>

    <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 20px 0;">

    @include('emails.partials.social-follow')

    <p style="color: #6b7280; font-size: 12px; margin-top: 12px;">
        <a href="https://clickinvoice.app" style="color:#0A66C2;">clickinvoice.app</a>
    </p>
    <p style="color: #9ca3af; font-size: 12px;">© {{ date('Y') }} ClickInvoice Ltd.</p>
</div>
</body>
</html>
