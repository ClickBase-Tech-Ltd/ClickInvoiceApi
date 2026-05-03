<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\InvoiceAuthorizationService;
use App\Services\InvoiceSupervisorOtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InvoiceSupervisorOtpController extends Controller
{
    public function __construct(
        protected InvoiceAuthorizationService $invoiceAuth,
        protected InvoiceSupervisorOtpService $otpService,
    ) {}

    public function requestOtp(Request $request, string $invoiceId)
    {
        $tenantId = $request->header('X-Tenant-ID');
        $user = Auth::user();

        if (! $tenantId) {
            return response()->json(['message' => 'Tenant ID is missing'], 400);
        }

        $invoice = Invoice::where('invoiceId', $invoiceId)->where('tenantId', $tenantId)->first();
        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        if (! $this->invoiceAuth->canAccessTenantInvoice($user, $invoice, $tenantId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $tenant = Tenant::where('tenantId', $tenantId)->first();
        if (! $tenant || ! $tenant->ownerId) {
            return response()->json(['message' => 'Tenant has no business owner on file.'], 422);
        }

        $canAct = $this->invoiceAuth->canVoidInvoice($user, $invoice, $tenantId)
            || $this->invoiceAuth->canAmendInvoiceItems($user, $invoice, $tenantId);
        if (! $canAct) {
            return response()->json(['message' => 'No void or line-edit permission on this invoice.'], 403);
        }

        try {
            $meta = $this->otpService->sendOwnerOtp($tenant, $invoice, $user);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'A 6-digit code was sent to the business owner\'s email.',
            'masked_email' => $meta['masked_email'],
        ]);
    }

    public function verifyOtp(Request $request, string $invoiceId)
    {
        $tenantId = $request->header('X-Tenant-ID');
        $user = Auth::user();

        if (! $tenantId) {
            return response()->json(['message' => 'Tenant ID is missing'], 400);
        }

        $invoice = Invoice::where('invoiceId', $invoiceId)->where('tenantId', $tenantId)->first();
        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        if (! $this->invoiceAuth->canAccessTenantInvoice($user, $invoice, $tenantId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $tenant = Tenant::where('tenantId', $tenantId)->first();
        if (! $tenant || ! $tenant->ownerId) {
            return response()->json(['message' => 'Tenant has no business owner on file.'], 422);
        }

        $validated = $request->validate([
            'otp' => 'required|string|size:6',
        ]);

        try {
            $token = $this->otpService->verifyAndIssueToken(
                $tenantId,
                (int) $user->id,
                $invoice->invoiceId,
                $validated['otp']
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Approved. You can void or edit line items for a limited time.',
            'supervisor_action_token' => $token,
            'expires_in_seconds' => 900,
        ]);
    }
}
