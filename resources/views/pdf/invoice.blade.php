{{-- resources/views/pdf/invoice.blade.php --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->userGeneratedInvoiceId ?? $invoice->invoiceId }}</title>
    <style>
        /* Base Styles */
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 11px;
            color: #1F2937;
            margin: 0;
            padding: 0;
            position: relative;
        }

        .container {
            max-width: 800px;
            margin: auto;
            padding: 3px;
        }

        /* Watermark */
        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            opacity: 0.1;
            pointer-events: none;
            z-index: 10;
            text-align: center;
        }

        .watermark img {
            width: 400px;
            height: auto;
        }

        /* Header + Invoice Title */
        .header-invoice {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #E5E7EB;
            padding: 3px 0;
            margin: 1px 0 5px 0;
            gap: 15px;
        }

        /* Left: Invoice info */
        .invoice-info {
            display: flex;
            flex-direction: column;
            justify-content: center;
            text-align: left;
        }

        .invoice-info h1 {
            font-size: 26px;
            font-weight: bold;
            color: #0A66C2;
            margin: 0;
        }

        .invoice-info p {
            font-size: 14px;
            margin: 1px 0;
            color: #1F2937;
        }

        .invoice-info .invoice-date {
            font-size: 12px;
            color: #374151;
        }

        /* Right: Company info */
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

        .company-details {
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .company-details h1 {
            font-size: 20px;
            font-weight: 700;
            color: #0A66C2;
            margin: 0;
        }

        .company-details p {
            margin: 1px 0;
            font-size: 14px;
            color: #374151;
        }

        /* Customer & Amount Info */
        .info-section {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }

        .customer-info h1 {
            font-size: 22px;
            font-weight: bold;
            margin: 0 0 2px 0;
        }

        .customer-info p {
            margin: 1px 0;
            font-size: 15px;
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
            margin-top: 2px;
        }

        .amount-info .total {
            font-size: 24px;
            font-weight: bold;
            margin: 0;
        }

        .amount-info .balance-label {
            font-size: 15px;
            color: #6B7280;
            margin: 1px 0 0 0;
        }

        .amount-info .balance-due {
            font-size: 17px;
            font-weight: 600;
            color: #0A66C2;
            margin: 1px 0 0 0;
        }

        /* Items Table */
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

        .total-row {
            background-color: #DBEAFE;
            font-weight: bold;
            font-size: 14pt;
        }

        /* Payment Section */
        .payment-section {
            margin: 1px 0;
            padding: 4px 6px;
            background-color: #F0FDF4;
            border-radius: 4px;
            font-size: 13.5px; /* increased base text size */
        }

        .payment-section h3 {
            margin: 0 0 3px 0;
            font-weight: 600;
            font-size: 14.5px; /* clearer section title */
        }

        /* Tight spacing + readable text */
        .payment-section p {
            margin: 0;
            line-height: 1.25;
            font-size: 13.5px;
        }

        .payment-section strong {
            font-weight: 600;
            font-size: 13.8px;
        }

        /* Notes */
        .notes {
            background-color: #F9FAFB;
            padding: 3px 3px; /* top/bottom padding reduced */
            border-radius: 4px;
            font-size: 12px;
        }

        .notes h3 {
            font-weight: 600;
            font-size: 12px; /* optional: make slightly smaller if needed */
        }

        /* Signature */
        .signature-section {
            margin-top: 5px;
            display: flex;
            justify-content: flex-end;
        }

        .signature-container {
            text-align: center;
        }

        .signature-image {
            max-width: 90px;
            height: 45px;
            object-fit: contain;
        }

        .signature-label {
            margin-top: 2px;
            font-size: 9px;
            color: #6B7280;
        }

        /* Footer */
        .footer-note {
            margin-top: 5px;
            text-align: center;
            font-size: 9px;
            color: #6B7280;
            padding: 5px;
            border-top: 1px solid #E5E7EB;
        }

        .footer-note a {
            color: #0A66C2;
            text-decoration: none;
        }
    </style>
</head>
<body>
    {{-- Watermark --}}
    @if((int) $current_plan === 1)
    <div class="watermark">
        <img src="https://app.clickinvoice.clickbase.tech/images/logo/logo.svg" alt="ClickInvoice Logo">
        <div class="watermark-note">Remove this logo for just $2</div>
    </div>
    @endif

    <div class="container">

        <!-- Header + Invoice Title Row -->
        <div class="header-invoice">

            <!-- Left: Invoice Title & Number -->
            <div class="invoice-info">
                <h1>INVOICE</h1>
                <p>#{{ $userGeneratedInvoiceId ?? $invoiceId }}</p>
                <p class="invoice-date">
                    Date: {{ \Carbon\Carbon::parse($invoice->invoiceDate ?? $invoice->created_at)->format('d M, Y') }}
                </p>
            </div>

            <!-- Right: Company Logo + Details -->
            <div class="company-info">
                <div class="company-logo">
                    @if($logoUrl)
                        <img src="{{ $logoUrl }}" alt="Company Logo">
                    @endif
                </div>
                <div class="company-details">
                    <h1>{{ $companyName }}</h1>
                    <p>{{ $companyEmail }}</p>
                    <p>{{ $companyPhone }}</p>
                </div>
            </div>
        </div>

        <!-- Customer & Amount Info -->
        <div class="info-section">
            <div class="customer-info">
                <h1>{{ $projectName }}</h1>
                <p class="customer-label">Bill To:</p>
                <p style="font-weight:600;">{{ $customerName }}</p>
                @if($customerAddress)
                    <p class="customer-details">{{ $customerAddress }}</p>
                @endif
                @if($customerEmail)
                    <p class="customer-details">{{ $customerEmail }}</p>
                @endif
                @if($customerPhone)
                    <p class="customer-details">{{ $customerPhone }}</p>
                @endif
            </div>
            <div class="amount-info">
                <p class="total">{{ $currencySymbol }} {{ number_format($totalAmount,2) }}</p>
                <p class="balance-label">Balance Due</p>
                <p class="balance-due">{{ $currencySymbol }} {{ number_format($balanceDue,2) }}</p>
            </div>
        </div>

        <!-- Items Table - Updated -->
        <table>
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="text-center">Qty</th>
                    <th class="text-right">Unit Price</th>
                    <th class="text-right">Discount</th>
                    <th class="text-right">Line Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $item)
                @php
                    $gross = $item['quantity'] * ($item['amount'] + ($item['discountAmount'] ?? 0));
                    $unitPrice = $gross / max(1, $item['quantity']);
                    $discount = $item['discountAmount'] ?? 0;
                @endphp
                <tr>
                    <td>{{ $item['description'] }}</td>
                    <td class="text-center">{{ $item['quantity'] }}</td>
                    <td class="text-right">{{ $currencySymbol }} {{ number_format($unitPrice, 2) }}</td>
                    <td class="text-right {{ $discount > 0 ? 'discount-row' : '' }}">
                        @if($discount > 0)
                            {{ $currencySymbol }} {{ number_format($discount, 2) }}
                        @else
                            —
                        @endif
                    </td>
                    <td class="text-right">{{ $currencySymbol }} {{ number_format($item['amount'], 2) }}</td>
                </tr>
                @endforeach

                <!-- Totals -->
                @php
                    $totalDiscount = 0;
                    $subtotalBefore = 0;
                    foreach($items as $item) {
                        $disc = $item['discountAmount'] ?? 0;
                        $totalDiscount += $disc;
                        $subtotalBefore += $item['quantity'] * ($item['amount'] + $disc);
                    }
                @endphp

                <tr>
                    <td colspan="4" class="text-right font-bold">Subtotal (before discount)</td>
                    <td class="text-right">{{ $currencySymbol }} {{ number_format($subtotalBefore, 2) }}</td>
                </tr>

                <tr>
                    <td colspan="4" class="text-right font-bold discount-row">Total Discount</td>
                    <td class="text-right discount-row">{{ $currencySymbol }} {{ number_format($totalDiscount, 2) }}</td>
                </tr>

                <tr>
                    <td colspan="4" class="text-right font-bold">Subtotal (after discount)</td>
                    <td class="text-right">{{ $currencySymbol }} {{ number_format($subtotal, 2) }}</td>
                </tr>

                <tr>
                    <td colspan="4" class="text-right font-bold">Tax ({{ $taxPercentage }}%)</td>
                    <td class="text-right">{{ $currencySymbol }} {{ number_format($taxAmount, 2) }}</td>
                </tr>

                <tr class="total-row">
                    <td class="text-right">Total</td>
                    <td class="text-right">{{ $currencySymbol }} {{ number_format($totalAmount, 2) }}</td>
                </tr>
            </tbody>
        </table>

        <!-- Payment Section -->
        <div class="payment-section">
            <h3>Payment Details</h3>
            <p><strong>Account Name:</strong> {{ $accountName }}</p>
            <p><strong>Account Number:</strong> {{ $accountNumber }}</p>
            <p><strong>Bank:</strong> {{ $bank }}</p>
        </div>

        <!-- Notes -->
        @if($notes)
        <div class="notes">
            <h3>Notes</h3>
            <p>{{ $notes }}</p>
        </div>
        @endif

        <!-- Receipt Notes / Appreciation -->
        <div class="notes" style="white-space: nowrap;">
            <p>
                Thanks for your patronage! For any questions, please contact <strong>{{ $companyName }}</strong> via 
                <a href="mailto:{{ $companyEmail }}" style="color: #0A66C2; text-decoration: none;">{{ $companyEmail }}</a> 
                or call {{ $companyPhone }}.
            </p>
        </div>

        <!-- Signature -->
        @if($signatureUrl)
        <div class="signature-section">
            <div class="signature-container">
                <img src="{{ $signatureUrl }}" class="signature-image" alt="Authorized Signature">
                <p class="signature-label">Authorized Signature</p>
            </div>
        </div>
        @endif

        <!-- Footer -->
        @if((int) $current_plan === 1)
        <div class="footer-note">
            This invoice was generated at
            <strong><a href="https://clickinvoice.app" target="_blank">clickinvoice.app</a></strong>.
            Visit ClickInvoice to begin generating yours.
        </div>
        @endif

    </div>
</body>
</html>