<?php
// app/Http/Controllers/InvoicePdfController.php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Services\InvoicePdfService;
use Illuminate\Http\Request;

class InvoicePdfController extends Controller
{
    protected $pdfService;

    public function __construct(InvoicePdfService $pdfService)
    {
        $this->pdfService = $pdfService;
    }

    public function download(Request $request, $id)
    {
        // return $id;
        // return $invoice = Invoice::with(['creator'])
         $invoice = Invoice::with(['items', 'currencyDetail', 'tenant', 'customer', 'creator'])
            ->where('invoiceId', $id)
            ->first();

        return $this->pdfService->downloadInvoicePdf($invoice);
    }


    public function downloadReceipt(Request $request, $id)
    {
        // return $id;
        $receipt = Invoice::with(['items', 'currencyDetail', 'tenant', 'customer'])
            ->where('receiptId', $id)
            ->first();

        return $this->pdfService->downloadReceiptPdf($receipt);
    }

    public function stream($id)
    {
        $invoice = Invoice::with(['items', 'currencyDetail', 'tenant', 'customer'])
            ->findOrFail($id);

        return $this->pdfService->streamInvoicePdf($invoice);
    }

    public function generate($id)
    {
        $invoice = Invoice::with(['items', 'currencyDetail', 'tenant', 'customer'])
            ->findOrFail($id);

        $result = $this->pdfService->generateInvoicePdf($invoice);

        return response()->json([
            'success' => true,
            'data' => [
                'pdf_url' => $result['full_path'],
                'pdf_path' => $result['path'],
                'filename' => $result['filename'],
                'invoice' => $invoice
            ]
        ]);
    }

public function sendEmail(Request $request, $id)
{
    // Find invoice by invoiceId
    $invoice = Invoice::with(['items', 'currencyDetail', 'tenant', 'customer'])
        ->where('invoiceId', $id)
        ->firstOrFail();

    if ($invoice->voided_at || strtoupper((string) $invoice->status) === 'VOID') {
        return response()->json([
            'success' => false,
            'message' => 'This invoice is void and cannot be emailed.',
        ], 422);
    }

    // Get customer's default email
    $customerEmail = $invoice->customer?->customerEmail;

    // Allow override via request
    $requestedEmail = $request->input('email');

    // Determine final recipient email
    $toEmail = $requestedEmail && filter_var($requestedEmail, FILTER_VALIDATE_EMAIL)
        ? $requestedEmail
        : $customerEmail;

    if (!$toEmail) {
        return response()->json([
            'success' => false,
            'message' => 'No valid email address provided'
        ], 400);
    }

    // Determine which name to use in the email template
    $isAlternateEmail = ($toEmail !== $customerEmail);
    $emailCustomerName = $isAlternateEmail 
        ? 'Customer' 
        : ($invoice->customer?->customerName ?? $invoice->accountName);

    // Generate PDF
    $result = $this->pdfService->generateInvoicePdf($invoice);

    try {
        \Mail::send('emails.invoice', [
            'invoice' => $invoice,
            'customerName' => $emailCustomerName  // ← Now conditionally "Customer" or real name
        ], function ($message) use ($invoice, $toEmail, $result) {
            $message->to($toEmail)
                ->subject('Invoice: ' . ($invoice->userGeneratedInvoiceId ?? $invoice->invoiceId))
                ->attachData($result['pdf_content'], $result['filename'], [
                    'mime' => 'application/pdf',
                ]);
        });

        // Update invoice
        $invoice->update([
            'sent_at' => now(),
            'sent_to' => $toEmail
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Invoice sent successfully to ' . $toEmail
        ]);

    } catch (\Exception $e) {
        \Log::error('Failed to send invoice email: ' . $e->getMessage(), [
            'invoice_id' => $id,
            'target_email' => $toEmail,
            'error' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to send email. Please try again later.'
        ], 500);
    }
}

public function sendReceiptEmail(Request $request, $id)
{
    // Find receipt by receiptId
    $receipt = Invoice::with(['items', 'currencyDetail', 'tenant', 'customer'])
        ->where('receiptId', $id)
        ->firstOrFail();

    if ($receipt->voided_at || strtoupper((string) $receipt->status) === 'VOID') {
        return response()->json([
            'success' => false,
            'message' => 'This receipt is void and cannot be emailed.',
        ], 422);
    }

    // Get customer's default email
    $customerEmail = $receipt->customer?->customerEmail;

    // Allow override via request (premium alternate email feature)
    $requestedEmail = $request->input('email');

    // Determine final recipient email
    $toEmail = $requestedEmail && filter_var($requestedEmail, FILTER_VALIDATE_EMAIL)
        ? $requestedEmail
        : $customerEmail;

    if (!$toEmail) {
        return response()->json([
            'success' => false,
            'message' => 'No valid email address provided'
        ], 400);
    }

    // Determine name to use in email template
    $isAlternateEmail = ($toEmail !== $customerEmail);
    $emailCustomerName = $isAlternateEmail 
        ? 'Customer' 
        : ($receipt->customer?->customerName ?? $receipt->accountName);

    // Generate PDF
    $result = $this->pdfService->generateReceiptPdf($receipt);

    try {
        // Build HTML with an embedded inline logo so email clients receive the image as CID
        \Mail::send([], [], function ($message) use ($receipt, $toEmail, $result, $emailCustomerName) {
            $message->to($toEmail)
                ->subject('Receipt: ' . ($receipt->userGeneratedReceiptId ?? $receipt->receiptId));

            // Embed logo and render the view with the returned CID.
            // Prefer a PNG (better Gmail support), fall back to SVG or local files.
            $logoCid = null;
            $remotePngUrl = 'https://app.clickinvoice.app/images/logo/logo.png';
            $remoteSvgUrl = 'https://app.clickinvoice.app/images/logo/logo.svg';
            try {
                $logoData = @file_get_contents($remotePngUrl);
                if ($logoData !== false) {
                    $logoCid = $message->embedData($logoData, 'logo.png', 'image/png');
                } else {
                    $logoData = @file_get_contents($remoteSvgUrl);
                    if ($logoData !== false) {
                        $logoCid = $message->embedData($logoData, 'logo.svg', 'image/svg+xml');
                    } else {
                        // Try local PNG then local SVG fallbacks
                        $localPng = public_path('images/logo.png');
                        $localSvg = public_path('images/logo2.svg');
                        if (file_exists($localPng)) {
                            $logoCid = $message->embed($localPng);
                        } elseif (file_exists($localSvg)) {
                            $logoCid = $message->embed($localSvg);
                        }
                    }
                }
            } catch (\Exception $e) {
                // fallback: leave logoCid null so view uses hosted URL
                \Log::warning('Could not embed receipt logo: ' . $e->getMessage());
            }

            $html = view('emails.receipt', [
                'receipt' => $receipt,
                'customerName' => $emailCustomerName,
                'logoCid' => $logoCid,
            ])->render();

            $message->html($html);

            // Attach PDF
            $message->attachData($result['pdf_content'], $result['filename'], [
                'mime' => 'application/pdf',
            ]);
        });

        // Update receipt with sent timestamp and actual recipient
        $receipt->update([
            'sent_at' => now(),
            'sent_to' => $toEmail
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Receipt sent successfully to ' . $toEmail
        ]);

    } catch (\Exception $e) {
        \Log::error('Failed to send receipt email: ' . $e->getMessage(), [
            'receipt_id' => $id,
            'target_email' => $toEmail,
            'error' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to send email. Please try again later.'
        ], 500);
    }
}

}
