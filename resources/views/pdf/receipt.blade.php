{{-- resources/views/pdf/receipt.blade.php --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Receipt {{ $userGeneratedReceiptId ?? $receiptId }}</title>
    <style>
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 11px;
            color: #1F2937;
            margin: 0;
            padding: 0;
        }

        .container {
            max-width: 800px;
            margin: auto;
            padding: 20px;
        }

        .header-invoice {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #E5E7EB;
            padding-bottom: 10px;
            margin-bottom: 25px;
        }

        .invoice-info {
            display: flex;
            flex-direction: column;
        }

        .invoice-info h1 {
            font-size: 28px;
            font-weight: bold;
            color: #0A66C2;
            margin: 0 0 5px 0;
        }

        .invoice-info p {
            margin: 2px 0;
            font-size: 13px;
            color: #374151;
        }

        .company-info {
            display: flex;
            flex-wrap: nowrap;
            align-items: center;
            gap: 10px;
            text-align: right;
        }

        .company-logo img {
            height: 65px;
            width: auto;
            object-fit: contain;
        }

        .company-details h1 {
            font-size: 20px;
            font-weight: 700;
            color: #0A66C2;
            margin: 0 0 3px 0;
        }

        .company-details p {
            margin: 1px 0;
            font-size: 12px;
            color: #374151;
        }

        .info-section {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .customer-info h2 {
            font-size: 15px;
            font-weight: bold;
            margin: 0 0 2px 0;
        }

        .customer-info p {
            margin: 1px 0;
            font-size: 13px;
            color: #111827;
        }

        .customer-label {
            font-weight: 600;
            color: #374151;
            margin-top: 2px;
            font-size: 14px;
        }

        .customer-details {
            font-size: 12px;
            color: #6B7280;
            margin-top: 1px;
            white-space: pre-line;
        }

        .amount-info {
            text-align: right;
            min-width: 150px;
        }

        .amount-info .total {
            font-size: 24px;
            font-weight: bold;
            margin: 0;
        }

        .amount-info .balance-label {
            font-size: 17px;
            color: #6B7280;
            margin: 1px 0 0 0;
        }

        .amount-info .balance-due {
            font-size: 17px;
            font-weight: 600;
            color: #0A66C2;
            margin: 1px 0 0 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 5px;
            font-size: 13px;
        }

        table th {
            background-color: #F3F4F6;
            text-align: left;
            padding: 8px 6px;
            border-bottom: 1px solid #E5E7EB;
            font-weight: 600;
        }

        table td {
            padding: 8px 6px;
            border-bottom: 1px solid #E5E7EB;
        }

        table .text-right {
            text-align: right;
        }

        table .text-center {
            text-align: center;
        }

        .discount-row {
            color: #dc2626;
        }

        .discount-row-bold {
            color: #dc2626;
            font-weight: 700;
        }

        .total-row {
            background-color: #DBEAFE;
            font-weight: bold;
            font-size: 14pt;
        }

        .paid-row {
            font-weight: bold;
            font-size: 13pt;
            color: #111827;
            background-color: rgba(10,102,194,0.08);
        }

        .payment-section {
            margin: 1px 0;
            padding: 4px 6px;
            background-color: #F0FDF4;
            border-radius: 4px;
            font-size: 13.5px;
        }

        .payment-section h3 {
            margin: 0 0 8px 0;
            font-weight: 600;
            font-size: 14.5px;
        }

        .payment-section p {
            margin: 4px 0;
            line-height: 1.4;
        }

        .payment-section strong {
            font-weight: 600;
        }

        .notes {
            background-color: #F9FAFB;
            padding: 12px;
            border-radius: 6px;
            font-size: 12px;
            margin: 20px 0;
        }

        .notes h3 {
            font-weight: 600;
            margin: 0 0 8px 0;
            font-size: 13px;
        }

        .signature-section {
            margin-top: 40px;
            display: flex;
            justify-content: flex-end;
        }

        .signature-container {
            text-align: center;
        }

        .signature-image {
            max-width: 140px;
            height: 60px;
            object-fit: contain;
        }

        .signature-label {
            margin-top: 6px;
            font-size: 10px;
            color: #6B7280;
        }

        .amount {
            font-family: 'DejaVu Sans', monospace;
        }

        .footer-note {
            margin-top: 40px;
            text-align: center;
            font-size: 9px;
            color: #6B7280;
            padding: 12px 0;
            border-top: 1px solid #E5E7EB;
        }

        .footer-note a {
            color: #0A66C2;
            text-decoration: none;
        }
    </style>
</head>
<body>

    <div class="container">

        <div class="header-invoice" style="position:relative; background: #fff; box-shadow: 0 2px 12px #e5e7eb; border-radius: 12px; padding: 0; margin-top: -8px; margin-bottom: 10px;">
            <table style="width:100%; border:none; border-collapse:collapse; margin-bottom:0;">
                <tr>
                    <td style="width:60%; vertical-align:top; padding:0;">
                        <div style="display:flex; align-items:flex-start; gap:0px;">
                            <div class="company-logo">
                                @if($logoUrl)
                                    <img src="{{ $logoUrl }}" alt="Company Logo" style="height: 90px; width: auto; object-fit: contain;">
                                @endif
                            </div>
                            <div class="company-details" style="font-size: 15px; color: #1F2937; margin-top:2px;">
                                <div style="font-size: 22px; font-weight: 800; color: #0A66C2; margin-bottom: 0px; margin-top:-8px;">{{ $companyName }}</div>
                                <div>{{ $companyAddress }}</div>
                                <div>{{ $companyEmail }}</div>
                                <div>{{ $companyPhone }}</div>
                                @if(!empty($companyTaxId))
                                <div style="font-weight: bold; color: #374151;">Tax ID: {{ $companyTaxId }}</div>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td style="width:40%; vertical-align:top; text-align:right; padding:0;">
                        <div style="display:flex; align-items:flex-start; gap:0px;">
                            <div style="font-size: 28px; font-weight: 900; color: #0A66C2; letter-spacing: 2px; margin-bottom: 0px;">RECEIPT</div>
                            <div style="display:flex; flex-direction:column; align-items:flex-end;">
                                <div style="font-weight:700; font-size:15px; color:#374151;">Receipt #: {{ $userGeneratedReceiptId ?? $receiptId }}</div>
                                <div style="font-weight:700; font-size:14px; color:#374151;">Date: {{ \Carbon\Carbon::parse($receiptDate ?? $updated_at)->format('d M, Y') }}</div>
                            </div>
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <table style="width:100%; border:none; border-collapse:collapse; margin-bottom:16px;">
            <tr>
                <td style="width:60%; vertical-align:top; padding:0;">
                    <div class="customer-info">
                        <h1 style="font-size:22px; font-weight:bold; margin:0 0 2px 0;">{{ $projectName }}</h1>
                        <p class="customer-label" style="font-weight:600; color:#374151; margin-top:2px; font-size:14px;">Received From:</p>
                        <p style="font-weight:600; font-size:15px; color:#111827; margin:1px 0;">{{ $customerName }}</p>
                        @if($customerAddress)
                            <p class="customer-details" style="font-size:12px; color:#6B7280; margin-top:1px; white-space:pre-line;">{{ $customerAddress }}</p>
                        @endif
                        @if($customerEmail)
                            <p class="customer-details" style="font-size:12px; color:#6B7280; margin-top:1px; white-space:pre-line;">{{ $customerEmail }}</p>
                        @endif
                        @if($customerPhone)
                            <p class="customer-details" style="font-size:12px; color:#6B7280; margin-top:1px; white-space:pre-line;">{{ $customerPhone }}</p>
                        @endif
                    </div>
                </td>
                <td style="width:40%; vertical-align:top; text-align:right; padding:0;">
                    <div class="amount-info" style="text-align:right; min-width:150px; margin-top:2px;">
                        <p class="total" style="font-size:24px; font-weight:bold; margin:0; color:#0A66C2;">{{ $currencySymbol }} {{ number_format($totalAmount, 2) }}</p>
                        <p class="balance-label" style="font-size:15px; color:#6B7280; margin:1px 0 0 0;">Amount Paid</p>
                        <p class="balance-due" style="font-size:17px; font-weight:600; color:#0A66C2; margin:1px 0 0 0;">{{ $currencySymbol }} {{ number_format($amountPaid, 2) }}</p>
                    </div>
                </td>
            </tr>
        </table>

        <!-- Items Table -->
        <table>
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="text-center">Qty</th>
                    <th class="text-right">Unit Price</th>
                    <th class="text-right">Line Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $item)
                <tr>
                    <td>{{ $item['description'] }}</td>
                    <td class="text-center">{{ $item['quantity'] }}</td>
                    <td class="text-right amount">{{ $currencySymbol }} {{ number_format($item['amount'], 2) }}</td>
                    <td class="text-right amount">{{ $currencySymbol }} {{ number_format($item['quantity'] * $item['amount'], 2) }}</td>
                </tr>
                @endforeach

                <!-- Totals -->
                <tr>
                    <td colspan="3" class="text-right font-bold">Subtotal</td>
                    <td class="text-right">{{ $currencySymbol }} {{ number_format($subtotal, 2) }}</td>
                </tr>

                @if($discountPercentage > 0 && $discountAmount > 0)
                <tr class="discount-row">
                    <td colspan="3" class="text-right discount-row-bold">
                        Discount ({{ number_format($discountPercentage, 1) }}%)
                    </td>
                    <td class="text-right discount-row">
                        -{{ $currencySymbol }} {{ number_format($discountAmount, 2) }}
                    </td>
                </tr>
                @endif

                <tr>
                    <td colspan="3" class="text-right font-bold">Subtotal after discount</td>
                    <td class="text-right">
                        {{ $currencySymbol }} {{ number_format($subtotalAfterDiscount, 2) }}
                    </td>
                </tr>

                @if($taxPercentage > 0 && $taxAmount > 0)
                <tr>
                    <td colspan="3" class="text-right font-bold">Tax ({{ number_format($taxPercentage, 1) }}%)</td>
                    <td class="text-right">
                        {{ $currencySymbol }} {{ number_format($taxAmount, 2) }}
                    </td>
                </tr>
                @endif

                // ...existing code...

                <tr class="paid-row">
                    <td colspan="3" class="text-right">Amount Paid</td>
                    <td class="text-right amount">
                        {{ $currencySymbol }} {{ number_format($amountPaid, 2) }}
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Payment Details -->
        <div class="payment-section">
            <h3>Payment Details</h3>
            <p><strong>Account Name:</strong> {{ $accountName }}</p>
            <p><strong>Account Number:</strong> {{ $accountNumber }}</p>
            <p><strong>Bank:</strong> {{ $bank }}</p>
        </div>

        <!-- Appreciation / Notes -->
        <div class="notes" style="white-space: nowrap;">
            <p>
                Thank you for your payment!<br>
                For any questions, please contact <strong>{{ $companyName }}</strong> via
                <a href="mailto:{{ $companyEmail }}" style="color: #0A66C2; text-decoration: none;">{{ $companyEmail }}</a>
                or call {{ $companyPhone }}.
            </p>
        </div>

        <!-- Signature -->
        <div class="signature-section">
            <div class="signature-container">
                @if($signatureUrl)
                    <img src="{{ $signatureUrl }}" alt="Authorized Signature" class="signature-image">
                @else
                    <div class="signature-placeholder">Authorized Signature</div>
                @endif
                <div class="signature-label">Authorized Signature</div>
            </div>
        </div>

    </div>
</body>
</html>