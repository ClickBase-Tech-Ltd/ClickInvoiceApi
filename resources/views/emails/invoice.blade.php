{{-- resources/views/emails/invoice.blade.php --}}
<!DOCTYPE html>
<html>
<head>
    <title>Invoice Notification</title>
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
            <h1>Invoice Notification</h1>
        </div>

        <div class="content">
            <p>Dear {{ $customerName }},</p>

            <p>Please find attached your invoice <strong>{{ $invoice->userGeneratedInvoiceId ?? $invoice->invoiceId }}</strong>.</p>

            <p><strong>Invoice Details:</strong></p>
            <ul>
                <li>Invoice Number: {{ $invoice->userGeneratedInvoiceId ?? $invoice->invoiceId }}</li>
                <li>Project: {{ $invoice->projectName }}</li>
                <li>Date: {{ \Carbon\Carbon::parse($invoice->invoiceDate ?? $invoice->created_at)->format('F d, Y') }}</li>
                @if(!empty($invoice->dueDate))
                <li>Due Date: {{ \Carbon\Carbon::parse($invoice->dueDate)->format('F d, Y') }}</li>
                @endif
                <li>Status: {{ strtoupper($invoice->status) }}</li>
            </ul>

            <p>The invoice is attached as a PDF file. You can also view it online if a link was provided.</p>

            <p>If you have any questions about this invoice, please don't hesitate to contact us.</p>

            <p>Best regards,<br>
            {{ $invoice->tenant->tenantName }}</p>
        </div>

        <div class="footer">
            <p>This is an automated message, please do not reply to this email.</p>
        </div>
    </div>
</body>
</html>
