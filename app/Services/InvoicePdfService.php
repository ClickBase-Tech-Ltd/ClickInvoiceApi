<?php
// app/Services/InvoicePdfService.php

namespace App\Services;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class InvoicePdfService
{
    public function generateInvoicePdf(Invoice $invoice)
    {
        $invoice->load([
            'items',
            'currencyDetail',
            'tenant',
            'customer'
        ]);

        $data = $this->prepareInvoiceData($invoice);

        $pdf = Pdf::loadView('pdf.invoice', $data);

        // Generate filename
        $filename = 'invoice_' . ($invoice->userGeneratedInvoiceId ?? $invoice->invoiceId) . '_' . time() . '.pdf';

        // Save to storage
        $path = 'invoices/' . $filename;
        Storage::disk('public')->put($path, $pdf->output());

        return [
            'path' => $path,
            'filename' => $filename,
            'full_path' => Storage::disk('public')->url($path),
            'pdf_content' => $pdf->output()
        ];
    }


    public function generateReceiptPdf(Invoice $invoice)
    {
        $invoice->load([
            'items',
            'currencyDetail',
            'tenant',
            'customer'
        ]);

        $data = $this->prepareReceiptData($invoice);

        $pdf = Pdf::loadView('pdf.receipt', $data);

        // Generate filename
        $filename = 'receipt_' . ($invoice->receiptId ?? $invoice->invoiceId) . '_' . time() . '.pdf';

        // Save to storage
        $path = 'receipts/' . $filename;
        Storage::disk('public')->put($path, $pdf->output());

        return [
            'path' => $path,
            'filename' => $filename,
            'full_path' => Storage::disk('public')->url($path),
            'pdf_content' => $pdf->output()
        ];
    }

    protected function prepareInvoiceData(Invoice $invoice)
{
    // Calculate gross subtotal (before global discount)
    $subtotal = 0;
    foreach ($invoice->items as $item) {
        $subtotal += $item->quantity * (float) $item->amount;
    }

    // Or more Laravel-style (if you prefer):
    // $subtotal = $invoice->items->sum(fn($item) => $item->quantity * (float) $item->amount);

    $taxPercentage     = (float) $invoice->taxPercentage;
    $discountPercentage = (float) $invoice->discountPercentage;

    // Apply global discount on subtotal
    $discountAmount = $subtotal * ($discountPercentage / 100);

    // Amount after discount
    $subtotalAfterDiscount = $subtotal - $discountAmount;

    // Tax is calculated on the amount AFTER discount
    $taxAmount = $subtotalAfterDiscount * ($taxPercentage / 100);

    // Final total
    $totalAmount = $subtotalAfterDiscount + $taxAmount;

    $amountPaid = (float) $invoice->amountPaid;
    $balanceDue = $totalAmount - $amountPaid;

    // Build image URLs (unchanged)
    $baseUrl = config('app.url');

    $logoUrl = null;
    $signatureUrl = null;

    /* ---------- LOGO ---------- */
    if (!empty($invoice->tenant->tenantLogo)) {
        $logoPath = storage_path('app/public/' . ltrim($invoice->tenant->tenantLogo, '/'));
        if (file_exists($logoPath) && is_file($logoPath)) {
            $logoData = base64_encode(file_get_contents($logoPath));
            $logoType = pathinfo($logoPath, PATHINFO_EXTENSION);
            $logoUrl = 'data:image/' . $logoType . ';base64,' . $logoData;
        }
    }

    /* ---------- SIGNATURE ---------- */
    if (!empty($invoice->tenant->authorizedSignature)) {
        $signaturePath = storage_path('app/public/' . ltrim($invoice->tenant->authorizedSignature, '/'));
        if (file_exists($signaturePath) && is_file($signaturePath)) {
            $signatureData = base64_encode(file_get_contents($signaturePath));
            $signatureType = pathinfo($signaturePath, PATHINFO_EXTENSION);
            $signatureUrl = 'data:image/' . $signatureType . ';base64,' . $signatureData;
        }
    }

    return [
        'invoice'               => $invoice,
        'current_plan'          => $invoice->creator->currentPlan,
        
        // Core money values — now correctly calculated
        'subtotal'              => $subtotal,
        'discountPercentage'    => $discountPercentage,
        'discountAmount'        => $discountAmount,
        'subtotalAfterDiscount' => $subtotalAfterDiscount,
        'taxPercentage'         => $taxPercentage,
        'taxAmount'             => $taxAmount,
        'totalAmount'           => $totalAmount,
        'amountPaid'            => $amountPaid,
        'balanceDue'            => $balanceDue,

        'currencySymbol'        => $invoice->currencyDetail->currencySymbol ?? '₦',
        'currencyCode'          => $invoice->currencyDetail->currencyCode ?? 'NGN',

        'logoUrl'               => $logoUrl,
        'signatureUrl'          => $signatureUrl,

        'companyName'           => $invoice->tenant->tenantName,
        'companyAddress'           => $invoice->tenant->tenantAddress,
        'companyEmail'          => $invoice->tenant->tenantEmail,
        'companyPhone'          => $invoice->tenant->tenantPhone,
        'companyTaxId'          => $invoice->tenant->taxId,

        'customerName'          => $invoice->customer->customerName ?? $invoice->accountName,
        'customerEmail'         => $invoice->customer->customerEmail ?? null,
        'customerPhone'         => $invoice->customer->customerPhone ?? null,
        'customerAddress'       => $invoice->customer->customerAddress ?? null,

        'projectName'           => $invoice->projectName,
        'invoiceDate'           => $invoice->invoiceDate,
        'dueDate'               => $invoice->dueDate,
        'status'                => $invoice->status,
        'invoiceId'             => $invoice->invoiceId,
        'userGeneratedInvoiceId'=> $invoice->userGeneratedInvoiceId,
        'notes'                 => $invoice->notes,

        'accountName'           => $invoice->accountName,
        'accountNumber'         => $invoice->accountNumber,
        'bank'                  => $invoice->bank,

        // Items — no discountAmount anymore (cleaned up)
        'items' => $invoice->items->map(function ($item) {
            return [
                'description' => $item->itemDescription,
                'quantity'    => (float) $item->quantity,
                'amount'      => (float) $item->amount,           // unit price (gross / before discount)
                // 'discountAmount' is intentionally removed
            ];
        })->all(),
    ];
}


   protected function prepareReceiptData(Invoice $receipt)
{
    // Calculate gross subtotal (before global discount)
    $subtotal = 0;
    foreach ($receipt->items as $item) {
        $subtotal += $item->quantity * (float) $item->amount;
    }

    // Alternative Laravel collection style (uncomment if preferred):
    // $subtotal = $receipt->items->sum(fn($item) => $item->quantity * (float) $item->amount);

    $taxPercentage      = (float) $receipt->taxPercentage;
    $discountPercentage = (float) $receipt->discountPercentage;

    // Global discount applied on subtotal
    $discountAmount        = $subtotal * ($discountPercentage / 100);
    $subtotalAfterDiscount = $subtotal - $discountAmount;

    // Tax calculated on amount after discount
    $taxAmount    = $subtotalAfterDiscount * ($taxPercentage / 100);
    $totalAmount  = $subtotalAfterDiscount + $taxAmount;

    $amountPaid   = (float) $receipt->amountPaid;
    $balanceDue   = $totalAmount - $amountPaid;

    // Build image URLs
    $logoUrl      = null;
    $signatureUrl = null;

    /* ---------- LOGO ---------- */
    if (!empty($receipt->tenant->tenantLogo)) {
        $logoPath = storage_path('app/public/' . ltrim($receipt->tenant->tenantLogo, '/'));
        if (file_exists($logoPath) && is_file($logoPath)) {
            $logoData = base64_encode(file_get_contents($logoPath));
            $logoType = pathinfo($logoPath, PATHINFO_EXTENSION);
            $logoUrl  = 'data:image/' . $logoType . ';base64,' . $logoData;
        }
    }

    /* ---------- SIGNATURE ---------- */
    if (!empty($receipt->tenant->authorizedSignature)) {
        $signaturePath = storage_path('app/public/' . ltrim($receipt->tenant->authorizedSignature, '/'));
        if (file_exists($signaturePath) && is_file($signaturePath)) {
            $signatureData = base64_encode(file_get_contents($signaturePath));
            $signatureType = pathinfo($signaturePath, PATHINFO_EXTENSION);
            $signatureUrl  = 'data:image/' . $signatureType . ';base64,' . $signatureData;
        }
    }

    return [
        'invoice'               => $receipt,   // keeping name 'invoice' if your receipt blade expects it
        'subtotal'              => $subtotal,
        'discountPercentage'    => $discountPercentage,
        'discountAmount'        => $discountAmount,
        'subtotalAfterDiscount' => $subtotalAfterDiscount,
        'taxPercentage'         => $taxPercentage,
        'taxAmount'             => $taxAmount,
        'totalAmount'           => $totalAmount,
        'amountPaid'            => $amountPaid,
        'balanceDue'            => $balanceDue,

        'currencySymbol'        => $receipt->currencyDetail->currencySymbol ?? '₦',
        'currencyCode'          => $receipt->currencyDetail->currencyCode   ?? 'NGN',

        'logoUrl'               => $logoUrl,
        'signatureUrl'          => $signatureUrl,

        'companyName'           => $receipt->tenant->tenantName,
        'companyAddress'           => $receipt->tenant->tenantAddress,
        'companyEmail'          => $receipt->tenant->tenantEmail,
        'companyPhone'          => $receipt->tenant->tenantPhone,
        'companyTaxId'          => $receipt->tenant->taxId,

        'customerName'          => $receipt->customer->customerName ?? $receipt->accountName,
        'customerEmail'         => $receipt->customer->customerEmail ?? null,
        'customerPhone'         => $receipt->customer->customerPhone ?? null,
        'customerAddress'       => $receipt->customer->customerAddress ?? null,

        'projectName'           => $receipt->projectName,
        'receiptDate'           => $receipt->updated_at,           // or use $receipt->receiptDate if you add that column
        'dueDate'               => $receipt->dueDate,
        'status'                => $receipt->status,
        'receiptId'             => $receipt->receiptId,
        'userGeneratedInvoiceId'=> $receipt->userGeneratedInvoiceId,
        'notes'                 => $receipt->notes,

        'accountName'           => $receipt->accountName,
        'accountNumber'         => $receipt->accountNumber,
        'bank'                  => $receipt->bank,

        // Items — cleaned up (no per-line discount anymore)
        'items' => $receipt->items->map(function ($item) {
            return [
                'description' => $item->itemDescription,
                'quantity'    => (float) $item->quantity,
                'amount'      => (float) $item->amount,   // unit price before discount
                // 'discountAmount' intentionally removed
            ];
        })->all(),
    ];
}



    protected function getImageUrl($path)
    {
        if (!$path) {
            return null;
        }

        if (str_starts_with($path, 'http')) {
            return $path;
        }

        // Check if file exists in storage
        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->url($path);
        }

        // Return full URL
        return config('app.url') . '/' . ltrim($path, '/');
    }

    public function downloadInvoicePdf(Invoice $invoice)
    {
        $data = $this->prepareInvoiceData($invoice);
        $filename = 'Invoice_' . ($invoice->userGeneratedInvoiceId ?? $invoice->invoiceId) . '.pdf';

        $pdf = Pdf::loadView('pdf.invoice', $data);

        return $pdf->download($filename);
    }

    public function downloadReceiptPdf(Invoice $receipt)
    {
        $data = $this->prepareReceiptData($receipt);
        $filename = 'Receipt_' . ($receipt->receiptId ?? $receipt->invoiceId) . '.pdf';

        $pdf = Pdf::loadView('pdf.receipt', $data);

        return $pdf->download($filename);
    }


    public function streamInvoicePdf(Invoice $invoice)
    {
        $data = $this->prepareInvoiceData($invoice);
        $pdf = Pdf::loadView('pdf.invoice', $data);

        return $pdf->stream();
    }
}
