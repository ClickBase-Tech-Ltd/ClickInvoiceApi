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
        // Calculate totals
        $subtotal = $invoice->items->sum('amount');
        $taxPercentage = (float) $invoice->taxPercentage;
        $taxAmount = $subtotal * ($taxPercentage / 100);
        $totalAmount = $subtotal + $taxAmount;
        $amountPaid = (float) $invoice->amountPaid;
        $balanceDue = $totalAmount - $amountPaid;

        // Build image URLs
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
            'invoice' => $invoice,
            'current_plan' => $invoice->creator->currentPlan,
            'subtotal' => $subtotal,
            'taxAmount' => $taxAmount,
            'totalAmount' => $totalAmount,
            'balanceDue' => $balanceDue,
            'amountPaid' => $amountPaid,
            'currencySymbol' => $invoice->currencyDetail->currencySymbol ?? '₦',
            'currencyCode' => $invoice->currencyDetail->currencyCode ?? 'NGN',
            'logoUrl' => $logoUrl,
            'signatureUrl' => $signatureUrl,
            'companyName' => $invoice->tenant->tenantName,
            'companyEmail' => $invoice->tenant->tenantEmail,
            'companyPhone' => $invoice->tenant->tenantPhone,
            'customerName' => $invoice->customer->customerName ?? $invoice->accountName,
            'customerEmail' => $invoice->customer->customerEmail ?? null,
            'customerPhone' => $invoice->customer->customerPhone ?? null,
            'customerAddress' => $invoice->customer->customerAddress ?? null,
            'projectName' => $invoice->projectName,
            'invoiceDate' => $invoice->invoiceDate,
            'dueDate' => $invoice->dueDate,
            'status' => $invoice->status,
            'invoiceId' => $invoice->invoiceId,
            'userGeneratedInvoiceId' => $invoice->userGeneratedInvoiceId,
            'notes' => $invoice->notes,
            'accountName' => $invoice->accountName,
            'accountNumber' => $invoice->accountNumber,
            'bank' => $invoice->bank,
            'taxPercentage' => $taxPercentage,
            'items' => $invoice->items->map(function ($item) {
                return [
                    'description' => $item->itemDescription,
                    'amount' => (float) $item->amount,
                    'discountAmount' => (float) $item->discountAmount,
                    'quantity' => $item->quantity
                ];
            })
        ];
    }



    protected function prepareReceiptData(Invoice $receipt)
    {
        // Calculate totals
        $subtotal = $receipt->items->sum('amount');
        $taxPercentage = (float) $receipt->taxPercentage;
        $taxAmount = $subtotal * ($taxPercentage / 100);
        $totalAmount = $subtotal + $taxAmount;
        $amountPaid = (float) $receipt->amountPaid;
        $balanceDue = $totalAmount - $amountPaid;

        // Build image URLs
        $baseUrl = config('app.url');
             $logoUrl = null;
$signatureUrl = null;


        // $imagePath = storage_path('app/public/tenant-logos/cons_logo.png');
// $imagePath = storage_path('app/public/' . ltrim($receipt->tenant->tenantLogo, '/'));
// $imageData = base64_encode(file_get_contents($imagePath));
// $imageType = pathinfo($imagePath, PATHINFO_EXTENSION);
// $logoUrl = 'data:image/' . $imageType . ';base64,' . $imageData;

// $imagePath = storage_path('app/public/' . ltrim($receipt->tenant->authorizedSignature, '/'));
// $imageData = base64_encode(file_get_contents($imagePath));
// $imageType = pathinfo($imagePath, PATHINFO_EXTENSION);
// $signatureUrl = 'data:image/' . $imageType . ';base64,' . $imageData;

/* ---------- LOGO ---------- */
if (!empty($receipt->tenant->tenantLogo)) {
    $logoPath = storage_path('app/public/' . ltrim($receipt->tenant->tenantLogo, '/'));

    if (file_exists($logoPath) && is_file($logoPath)) {
        $logoData = base64_encode(file_get_contents($logoPath));
        $logoType = pathinfo($logoPath, PATHINFO_EXTENSION);
        $logoUrl = 'data:image/' . $logoType . ';base64,' . $logoData;
    }
}

/* ---------- SIGNATURE ---------- */
if (!empty($receipt->tenant->authorizedSignature)) {
    $signaturePath = storage_path('app/public/' . ltrim($receipt->tenant->authorizedSignature, '/'));

    if (file_exists($signaturePath) && is_file($signaturePath)) {
        $signatureData = base64_encode(file_get_contents($signaturePath));
        $signatureType = pathinfo($signaturePath, PATHINFO_EXTENSION);
        $signatureUrl = 'data:image/' . $signatureType . ';base64,' . $signatureData;
    }
}

        return [
            'invoice' => $receipt,
            'subtotal' => $subtotal,
            'taxAmount' => $taxAmount,
            'totalAmount' => $totalAmount,
            'balanceDue' => $balanceDue,
            'amountPaid' => $amountPaid,
            'currencySymbol' => $invoice->currencyDetail->currencySymbol ?? '₦',
            'currencyCode' => $invoice->currencyDetail->currencyCode ?? 'NGN',
            'logoUrl' => $logoUrl,
            'signatureUrl' => $signatureUrl,
            'companyName' => $receipt->tenant->tenantName,
            'companyEmail' => $receipt->tenant->tenantEmail,
            'companyPhone' => $receipt->tenant->tenantPhone,
            'customerName' => $receipt->customer->customerName ?? $receipt->accountName,
            'customerEmail' => $receipt->customer->customerEmail ?? null,
            'customerPhone' => $receipt->customer->customerPhone ?? null,
            'customerAddress' => $receipt->customer->customerAddress ?? null,
            'projectName' => $receipt->projectName,
            'receiptDate' => $receipt->updated_at,
            'dueDate' => $receipt->dueDate,
            'status' => $receipt->status,
            'receiptId' => $receipt->receiptId,
            'userGeneratedInvoiceId' => $receipt->userGeneratedInvoiceId,
            'notes' => $receipt->notes,
            'accountName' => $receipt->accountName,
            'accountNumber' => $receipt->accountNumber,
            'bank' => $receipt->bank,
            'taxPercentage' => $taxPercentage,
            'items' => $receipt->items->map(function ($item) {
                return [
                    'description' => $item->itemDescription,
                    'amount' => (float) $item->amount,
                    'discountAmount' => (float) $item->discountAmount,
                    'quantity' => $item->quantity
                ];
            })
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
