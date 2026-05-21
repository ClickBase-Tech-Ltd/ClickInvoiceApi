{{-- resources/views/emails/receipt.blade.php --}}
<!DOCTYPE html>
<html>
<head>
    <title>Receipt Notification</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .logo { background-color: #ffffff; padding: 18px 0; text-align: center; border-radius:8px 8px 0 0; border-bottom:1px solid #e6eef9; }
        .logo img{ max-width:150px; display:block; margin:0 auto; }
        .header { background-color: #ffffff; color: #0A66C2; padding: 4px 20px; text-align: center; border-bottom:1px solid #e6eef9; }
        .header h1{ margin:0; font-size:20px; color:#0A66C2; }
        .content { padding: 20px; background-color: #f9fafb; }
        .footer { margin-top: 20px; padding: 10px; text-align: center; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">
            <img src="https://app.clickinvoice.app/images/logo/logo.svg" alt="ClickInvoice" width="140" style="display:block;margin:0 auto;" />
        </div>
        <div class="header">
            <h1>Payment Receipt</h1>
        </div>

        <div class="content">
            <p>Dear {{ $customerName }},</p>

            <p>Thank you for your payment! Please find attached your official receipt <strong>{{ $receipt->userGeneratedReceiptId ?? $receipt->receiptId }}</strong>.</p>

            <p><strong>Receipt Details:</strong></p>
            <ul>
                <li>Receipt Number: {{ $receipt->receiptId }}</li>
                <li>Project: {{ $receipt->projectName }}</li>
                <li>Date: {{ \Carbon\Carbon::parse($receipt->receiptDate ?? $receipt->invoiceDate ?? $receipt->updated_at)->format('F d, Y') }}</li>
                <li>Status: {{ strtoupper($receipt->status) }}</li>
            </ul>

            <p>The receipt is attached as a PDF file for your records.</p>

            <p>If you have any questions about this receipt or your payment, please don't hesitate to contact us.</p>

            <p>Best regards,<br>
            {{ $receipt->tenant->tenantName }}</p>
        </div>

        <div class="footer">
            <p>This is an automated message, please do not reply to this email.</p>
        </div>
    </div>
</body>
</html>