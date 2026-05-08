<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\InvoiceAuditEvent;
use App\Services\InvoiceAuthorizationService;
use App\Services\InvoiceTotalsService;
use App\Services\InvoiceSupervisorOtpService;

class InvoiceController extends Controller
{
    public function __construct(
        protected InvoiceAuthorizationService $invoiceAuth,
        protected InvoiceTotalsService $invoiceTotals,
        protected InvoiceSupervisorOtpService $supervisorOtp,
    ) {}

    protected function recordInvoiceAudit(
        Invoice $invoice,
        string $action,
        ?string $reason,
        ?array $payload = null
    ): void {
        InvoiceAuditEvent::create([
            'invoice_id' => $invoice->invoiceId,
            'tenant_id' => $invoice->tenantId,
            'actor_user_id' => Auth::id(),
            'action' => $action,
            'reason' => $reason,
            'payload' => $payload,
        ]);
    }

    /**
     * Store a new invoice with items, optional tax, and amountPaid.
     */
    public function index(){
        $invoices = Invoice::with('items', 'currencyDetail', 'customer')->get();
        return response()->json($invoices);
    }
    public function store(Request $request)
{
    $user = Auth::user();

    if (!$user->canCreateInvoice()) {
        return response()->json([
            'message' => 'Sorry you can\'t add any more invoices. Upgrade to premium to generate more invoices.'
        ], 403);
    }

    $tenantId = $request->header('X-Tenant-ID');

    $request->validate([
        'invoiceId'              => 'required|unique:invoices,invoiceId',
        'projectName'            => 'nullable|string|max:255',
        'invoiceDate'            => 'nullable|date',
        'dueDate'                => 'nullable|date',
        'currency'               => 'nullable|exists:currencies,currencyId',
        'tenantId'               => 'nullable|exists:tenants,tenantId',
        'createdBy'              => 'nullable|exists:users,id',
        'taxPercentage'          => 'nullable|numeric|min:0',
        'discountPercentage'     => 'nullable|numeric|min:0|max:100',
        'amountPaid'             => 'nullable|numeric|min:0',
        'items'                  => 'required|array|min:1',
        'items.*.itemDescription'=> 'required|string',
        'items.*.amount'         => 'required|numeric|min:0',     // unit price
        'items.*.quantity'       => 'required|numeric|integer|min:1',
        // 'items.*.discountAmount'  → removed from validation & usage
    ]);

    // ────────────────────────────────────────────────
    // 1. Calculate subtotal (gross before any discount/tax)
    // ────────────────────────────────────────────────
    $subtotal = 0;
    foreach ($request->items as $item) {
        $qty    = (float) ($item['quantity'] ?? 1);
        $amount = (float) ($item['amount'] ?? 0);   // unit price
        $subtotal += $qty * $amount;
    }

    // ────────────────────────────────────────────────
    // 2. Global discount (applied BEFORE tax - common in NG)
    // ────────────────────────────────────────────────
    $discountPercentage = (float) ($request->input('discountPercentage', 0));
    $discountAmount     = $subtotal * ($discountPercentage / 100);
    $amountAfterDiscount = max(0, $subtotal - $discountAmount);

    // ────────────────────────────────────────────────
    // 3. Tax (VAT) on discounted amount
    // ────────────────────────────────────────────────
    $taxPercentage = (float) ($request->input('taxPercentage', 0));
    $taxAmount     = $amountAfterDiscount * ($taxPercentage / 100);

    // ────────────────────────────────────────────────
    // 4. Grand total & balance
    // ────────────────────────────────────────────────
    $totalAmount = $amountAfterDiscount + $taxAmount;
    $amountPaid  = (float) ($request->input('amountPaid', 0));
    $balanceDue  = max(0, $totalAmount - $amountPaid);

    // ────────────────────────────────────────────────
    // 5. Get tenant default currency if needed
    // ────────────────────────────────────────────────
    $tenant = Tenant::where('tenantId', $tenantId)->first();
    $currency = $request->input('currency', $tenant ? $tenant->currency : null);

    // ────────────────────────────────────────────────
    // 6. Create main invoice record
    // ────────────────────────────────────────────────
    $invoicePayload = array_merge(
        $request->only([
            'invoiceId',
            'userGeneratedInvoiceId',
            'projectName',
            'invoiceDate',
            'dueDate',
            'invoicePassword',
            'notes',
            'accountName',
            'accountNumber',
            'bank',
            'taxPercentage',
            'discountPercentage',
            'customerId'
        ]),
        []
    );

    // Backward compatibility: some environments may not yet have the
    // new totals columns from the supervisory migration.
    if (Schema::hasColumn('invoices', 'subtotal')) {
        $invoicePayload['subtotal'] = $subtotal;
    }
    if (Schema::hasColumn('invoices', 'discountAmount')) {
        $invoicePayload['discountAmount'] = $discountAmount;
    }
    if (Schema::hasColumn('invoices', 'taxAmount')) {
        $invoicePayload['taxAmount'] = $taxAmount;
    }
    if (Schema::hasColumn('invoices', 'totalAmount')) {
        $invoicePayload['totalAmount'] = $totalAmount;
    }

    $invoicePayload = array_merge($invoicePayload, [
        'amountPaid'     => $amountPaid,
        'balanceDue'     => $balanceDue,
        'tenantId'       => $tenantId,
        'createdBy'      => auth()->id(),
        'currency'       => $currency,
        'status'         => $balanceDue >= $totalAmount ? 'UNPAID' : ($amountPaid > 0 ? 'PARTIAL' : 'PAID'),
    ]);

    $invoice = Invoice::create($invoicePayload);

    // ────────────────────────────────────────────────
    // 7. Create invoice items (NO per-line discount anymore)
    // ────────────────────────────────────────────────
    foreach ($request->items as $item) {
        $invoice->items()->create([
            'itemDescription' => $item['itemDescription'],
            'quantity'        => (int) ($item['quantity'] ?? 1),
            'amount'          => (float) ($item['amount'] ?? 0),  // unit price
            // 'discountAmount'  → removed
        ]);
    }

    // Reload with relations for response
    $invoice->load('items');

    return response()->json([
        'message' => 'Invoice created successfully',
        'invoice' => $invoice
    ], 201);
}
    /**
     * Get all invoices for the authenticated user.
     */
    public function getUserInvoices(Request $request)
    {
        $tenantId = $request->header('X-Tenant-ID');
        $userId = Auth::id();
        $user = Auth::user();

        $q = Invoice::with('items', 'currencyDetail', 'customer')
            ->where('tenantId', $tenantId);

        if (! $this->invoiceAuth->isTenantSupervisor($user, $tenantId)) {
            $q->where('createdBy', $userId);
        }

        return response()->json($q->orderByDesc('updated_at')->get());
    }


    public function getUserReceipts(Request $request)
    {
        $tenantId = $request->header('X-Tenant-ID');
        $userId = Auth::id();
        $user = Auth::user();

        $q = Invoice::with('items', 'currencyDetail', 'customer')
            ->where('tenantId', $tenantId)
            ->where(function ($query) {
                $query->where('status', 'PAID')
                    ->orWhere('status', 'PARTIAL_PAYMENT');
            });

        if (! $this->invoiceAuth->isTenantSupervisor($user, $tenantId)) {
            $q->where('createdBy', $userId);
        }

        return response()->json($q->get());
    }

     public function getLast5UserInvoices(Request $request)
{
    $tenantId = $request->header('X-Tenant-ID');
    $userId = Auth::id();
    $user = Auth::user();

    if (!$tenantId) {
        return response()->json([
            'message' => 'Tenant ID is missing'
        ], 400);
    }

    $q = Invoice::with([
            'items',
            'currencyDetail',
            'customer',
        ])
        ->where('tenantId', $tenantId);

    if (! $this->invoiceAuth->isTenantSupervisor($user, $tenantId)) {
        $q->where('createdBy', $userId);
    }

    $invoices = $q->latest()
        ->limit(5)
        ->get();

    return response()->json($invoices, 200);
}


// public function invoiceSummary(Request $request)
// {
//     $tenantId = $request->header('X-Tenant-ID');
//     $userId = Auth::id();

//     if (!$tenantId) {
//         return response()->json([
//             'message' => 'Tenant ID is missing'
//         ], 400);
//     }

//     $summary = Invoice::where('tenantId', $tenantId)
//         ->where('createdBy', $userId)
//         ->selectRaw('
//             COALESCE(SUM(amountPaid), 0) as collected,
//             COALESCE(SUM(balanceDue), 0) as outstanding
//         ')
//         ->first();

//     return response()->json([
//         'collected' => (float) $summary->collected,
//         'outstanding' => (float) $summary->outstanding,
//     ]);


public function invoiceSummary(Request $request)
{
    $tenantId = $request->header('X-Tenant-ID');
    $userId = Auth::id();

    if (!$tenantId) {
        return response()->json([
            'message' => 'Tenant ID is missing'
        ], 400);
    }

    // 1. Get the aggregated amounts
    $amounts = Invoice::where('tenantId', $tenantId)
        ->where('createdBy', $userId)
        ->selectRaw('
            COALESCE(SUM(amountPaid), 0) AS collected,
            COALESCE(SUM(balanceDue), 0) AS outstanding
        ')
        ->first();

    // 2. Get currency from any one invoice (preferably the latest)
    $currencyInfo = Invoice::where('tenantId', $tenantId)
        ->where('createdBy', $userId)
        ->join('currencies', 'invoices.currency', '=', 'currencies.currencyId')
        ->select('currencies.currencyCode AS currency_code', 'currencies.currencySymbol AS currency_symbol')
        ->orderBy('invoices.created_at', 'desc') // get from most recent invoice
        ->first();

    // If no invoices exist
    if (!$amounts) {
        return response()->json([
            'collected'       => 0.0,
            'outstanding'     => 0.0,
            'currency_code'   => 'USD',
            'currency_symbol' => '$',
        ]);
    }

    return response()->json([
        'collected'       => (float) $amounts->collected,
        'outstanding'     => (float) $amounts->outstanding,
        'currency_code'   => $currencyInfo?->currency_code ?? 'USD',
        'currency_symbol' => $currencyInfo?->currency_symbol ?? $this->getFallbackSymbol($currencyInfo?->currency_code ?? 'USD'),
    ]);
}

private function getFallbackSymbol(string $code): string
{
    return match (strtoupper($code)) {
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'NGN' => '₦',
        'GHS' => 'GH₵',
        'ZAR' => 'R',
        'KES' => 'KSh',
        default => strtoupper($code),
    };
}



public function adminInvoiceSummary(Request $request)
{
    try {
        $query = Invoice::query();

        $range = strtolower((string) $request->query('range', ''));
        $scope = strtolower((string) $request->query('scope', 'range'));

        if ($scope !== 'all_time' && in_array($range, ['7d', '30d', '90d', 'ytd'])) {
            if ($range === 'ytd') {
                $query->whereDate('invoiceDate', '>=', now()->startOfYear());
            } else {
                $days = $range === '7d' ? 7 : ($range === '30d' ? 30 : 90);
                $query->whereDate('invoiceDate', '>=', now()->subDays($days));
            }
        }

        $summaries = $query
            ->leftJoin('currencies', 'invoices.currency', '=', 'currencies.currencyId')
            ->selectRaw('
                COALESCE(currencies.currencyCode, "UNKNOWN") AS currency_code,
                COALESCE(currencies.currencySymbol, "$") AS currency_symbol,
                COALESCE(currencies.country, "Unmapped") AS country,
                SUM(COALESCE(NULLIF(invoices.amountPaid, ""), 0) + 0) AS collected,
                SUM(COALESCE(NULLIF(invoices.balanceDue, ""), 0) + 0) AS outstanding
            ')
            ->groupBy(
                'currencies.currencyCode',
                'currencies.currencySymbol',
                'currencies.country'
            )
            ->orderByRaw('
                CASE WHEN currencies.currencyCode IS NULL THEN 1 ELSE 0 END ASC,
                SUM(COALESCE(NULLIF(invoices.amountPaid, ""), 0) + 0) + SUM(COALESCE(NULLIF(invoices.balanceDue, ""), 0) + 0) DESC
            ')
            ->get();

        return response()->json(
            $summaries->map(fn ($row) => [
                'currency_code'   => $row->currency_code,
                'currency_symbol' => $row->currency_symbol
                    ?? $this->getFallbackSymbol($row->currency_code ?? 'USD'),
                'country'         => $row->country,
                'collected'       => (float) ($row->collected ?? 0),
                'outstanding'     => (float) ($row->outstanding ?? 0),
            ])
        );
    } catch (\Throwable $e) {
        \Log::error('adminInvoiceSummary error: ' . $e->getMessage());
        return response()->json([], 200);
    }
}


    /**
     * Get a single invoice by tenant ID.
     */
    public function getInvoiceByTenant($tenantId)
    {
        $invoice = Invoice::with('items', 'currencyDetail',)
            ->where('tenantId', $tenantId)
            ->first();

        if (!$invoice) {
            return response()->json(['message' => 'Invoice not found for this tenant'], 404);
        }

        return response()->json($invoice);
    }

public function getInvoiceByInvoiceId(Request $request, $invoiceId)
    {
        $tenantId = $request->header('X-Tenant-ID');
        $user = Auth::user();

        $invoice = Invoice::with('items', 'currencyDetail', 'tenant', 'customer', 'creator')
            ->where('invoiceId', $invoiceId)
            ->where('tenantId', $tenantId)
            ->first();

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        if (! $this->invoiceAuth->canAccessTenantInvoice($user, $invoice, $tenantId)) {
            return response()->json(['message' => 'You do not have access to this invoice'], 403);
        }

        return response()->json([$invoice]);
    }


    public function getInvoiceByInvoiceIdForAdmin(Request $request, $invoiceId)
    {
        // $tenantId = $request->header('X-Tenant-ID');
        // $userId = Auth::id();

        $invoice = Invoice::with('items', 'currencyDetail', 'tenant', 'customer', 'creator')
            // ->where('createdBy', $userId)
            ->where('invoiceId', $invoiceId)
            // ->where('tenantId', $tenantId)
            ->get();

        return response()->json($invoice);
    }

    public function getReceiptByReceiptId(Request $request, $receiptId)
    {
        $tenantId = $request->header('X-Tenant-ID');
        $user = Auth::user();

        $invoice = Invoice::with('items', 'currencyDetail', 'tenant', 'customer', 'creator')
            ->where('receiptId', $receiptId)
            ->where('tenantId', $tenantId)
            ->first();

        if (! $invoice) {
            return response()->json(['message' => 'Receipt not found'], 404);
        }

        if (! $this->invoiceAuth->canAccessTenantInvoice($user, $invoice, $tenantId)) {
            return response()->json(['message' => 'You do not have access to this receipt'], 403);
        }

        return response()->json([$invoice]);
    }

public function getInvoiceAndReceiptsByCustomerId(Request $request, $customerId)
    {
        $tenantId = $request->header('X-Tenant-ID');
        $userId = Auth::id();
        $user = Auth::user();

        $q = Invoice::with('items', 'currencyDetail', 'tenant', 'customer')
            ->where('customerId', $customerId)
            ->where('tenantId', $tenantId);

        if (! $this->invoiceAuth->isTenantSupervisor($user, $tenantId)) {
            $q->where('createdBy', $userId);
        }

        return response()->json($q->get());
    }


    // public function getInvoiceAndReceiptsByCustomerId(Request $request, $customerId)
    // {
    //     $tenantId = $request->header('X-Tenant-ID');
    //     $userId = Auth::id();

    //     $invoice = Invoice::with('items', 'currencyDetail', 'tenant', 'customer')
    //         ->where('createdBy', $userId)
    //         ->where('customerId', $customerId)
    //         ->where('tenantId', $tenantId)
    //         ->get();

    //     return response()->json($invoice);
    // }


    public function getInvoicesForCustomer(Request $request, $customerId)
    {
        $tenantId = $request->header('X-Tenant-ID');
        $userId = Auth::id();
        $user = Auth::user();

        $q = Invoice::with('items', 'currencyDetail', 'tenant', 'customer')
            ->where('customerId', $customerId)
            ->where('status', 'UNPAID')
            ->where('tenantId', $tenantId);

        if (! $this->invoiceAuth->isTenantSupervisor($user, $tenantId)) {
            $q->where('createdBy', $userId);
        }

        return response()->json($q->get());
    }


public function getReceiptsForCustomer(Request $request, $customerId)
{
    $tenantId = $request->header('X-Tenant-ID');
    $userId = Auth::id();
    $user = Auth::user();

    $q = Invoice::with(['items', 'currencyDetail', 'tenant', 'customer'])
        ->where('customerId', $customerId)
        ->whereIn('status', ['PAID', 'PARTIAL_PAYMENT'])
        ->where('tenantId', $tenantId);

    if (! $this->invoiceAuth->isTenantSupervisor($user, $tenantId)) {
        $q->where('createdBy', $userId);
    }

    return response()->json($q->get());
}





//     public function updateInvoiceStatus(Request $request, $invoiceId)
// {
//     $invoice = Invoice::where('invoiceId', $invoiceId)->first();

//     if (!$invoice) {
//         return response()->json([
//             'message' => 'Invoice not found'
//         ], 404);
//     }

//     $validated = $request->validate([
//         'status' => 'required|string',
//         'amountPaid' => 'nullable|numeric|min:0'
//     ]);

//     $status = strtoupper($validated['status']);

//     // 🔹 PAID: clear balance, move everything to amountPaid
//     if ($status === 'PAID') {
//         $invoice->amountPaid = $invoice->amountPaid + $invoice->balanceDue;
//         $invoice->balanceDue = 0;
//         $invoice->status = 'PAID';
//     }

//     // 🔹 PARTIAL PAYMENT: use amount sent from frontend
//     elseif ($status === 'PARTIAL_PAYMENT') {
//         if (!isset($validated['amountPaid'])) {
//             return response()->json([
//                 'message' => 'amountPaid is required for partial payment'
//             ], 422);
//         }

//         $partialAmount = (float) $validated['amountPaid'];

//         if ($partialAmount > $invoice->balanceDue) {
//             return response()->json([
//                 'message' => 'Amount paid cannot exceed balance due'
//             ], 422);
//         }

//         $invoice->amountPaid += $partialAmount;
//         $invoice->balanceDue -= $partialAmount;
//         $invoice->status = 'PARTIAL_PAYMENT';
//     }

//     // 🔹 Other statuses (optional handling)
//     else {
//         $invoice->status = $status;
//     }

//     $invoice->save();

//     return response()->json([
//         'message' => 'Invoice updated successfully',
//         'invoice' => $invoice
//     ]);
// }

public function updateInvoiceStatus(Request $request, $invoiceId)
{
    $invoice = Invoice::where('invoiceId', $invoiceId)->first();

    if (!$invoice) {
        return response()->json([
            'message' => 'Invoice not found'
        ], 404);
    }

    if ($invoice->voided_at || strtoupper((string) $invoice->status) === 'VOID') {
        return response()->json([
            'message' => 'This invoice is void and cannot be updated.'
        ], 422);
    }

    $validated = $request->validate([
        'status' => 'required|string',
        'amountPaid' => 'nullable|numeric|min:0'
    ]);

    $status = strtoupper($validated['status']);

    if ($status === 'VOID') {
        return response()->json([
            'message' => 'Use POST /invoices/{invoiceId}/void with a reason to void an invoice.',
        ], 422);
    }

    /**
     * Generate receipt ID ONLY if:
     * - Status is PAID or PARTIAL_PAYMENT
     * - AND receiptId does not already exist
     */
    $receiptId = strtoupper(Str::random(2)) . mt_rand(1000000000, 9999999999);
    $shouldGenerateReceipt =
        in_array($status, ['PAID', 'PARTIAL_PAYMENT']) &&
        empty($invoice->receiptId);

    if ($shouldGenerateReceipt) {
        $invoice->receiptId = 'RCPT-' . $receiptId;
    }

    // 🔹 PAID: clear balance, move everything to amountPaid
    if ($status === 'PAID') {
        $invoice->amountPaid += $invoice->balanceDue;
        $invoice->balanceDue = 0;
        $invoice->status = 'PAID';
    }

    // 🔹 PARTIAL PAYMENT
    elseif ($status === 'PARTIAL_PAYMENT') {
        if (!isset($validated['amountPaid'])) {
            return response()->json([
                'message' => 'amountPaid is required for partial payment'
            ], 422);
        }

        $partialAmount = (float) $validated['amountPaid'];

        if ($partialAmount > $invoice->balanceDue) {
            return response()->json([
                'message' => 'Amount paid cannot exceed balance due'
            ], 422);
        }

        $invoice->amountPaid += $partialAmount;
        $invoice->balanceDue -= $partialAmount;
        $invoice->status = 'PARTIAL_PAYMENT';
    }

    // 🔹 Other statuses
    else {
        $invoice->status = $status;
    }

    $invoice->save();

    return response()->json([
        'message' => 'Invoice updated successfully',
        'invoice' => $invoice
    ]);
}

    public function invoiceCapabilities(Request $request, string $invoiceId)
    {
        $tenantId = $request->header('X-Tenant-ID');
        $user = Auth::user();

        if (! $tenantId) {
            return response()->json(['message' => 'Tenant ID is missing'], 400);
        }

        $invoice = Invoice::where('invoiceId', $invoiceId)
            ->where('tenantId', $tenantId)
            ->first();

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        if (! $this->invoiceAuth->canAccessTenantInvoice($user, $invoice, $tenantId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $tenant = Tenant::where('tenantId', $tenantId)->first();
        $canVoid = $this->invoiceAuth->canVoidInvoice($user, $invoice, $tenantId);
        $canAmend = $this->invoiceAuth->canAmendInvoiceItems($user, $invoice, $tenantId);
        $needsOwnerOtp = $tenant
            && $this->invoiceAuth->tenantHasOwnerForSupervisoryOtp($tenant)
            && ($canVoid || $canAmend);

        return response()->json([
            'canVoid' => $canVoid,
            'canAmendItems' => $canAmend,
            'isVoid' => (bool) $invoice->voided_at || strtoupper((string) $invoice->status) === 'VOID',
            'isTenantSupervisor' => $this->invoiceAuth->isTenantSupervisor($user, $tenantId),
            'requiresOwnerOtp' => $needsOwnerOtp,
            'isTenantOwner' => $tenant ? $this->invoiceAuth->isTenantOwner($user, $tenant) : false,
        ]);
    }

    public function voidInvoice(Request $request, string $invoiceId)
    {
        $tenantId = $request->header('X-Tenant-ID');
        $user = Auth::user();

        if (! $tenantId) {
            return response()->json(['message' => 'Tenant ID is missing'], 400);
        }

        $invoice = Invoice::with('items')->where('invoiceId', $invoiceId)->where('tenantId', $tenantId)->first();

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        if (! $this->invoiceAuth->canVoidInvoice($user, $invoice, $tenantId)) {
            return response()->json([
                'message' => 'You cannot void this invoice (wrong tenant, insufficient permission, or invoice is not eligible). Only unpaid or overdue invoices can be voided.',
            ], 403);
        }

        $tenant = Tenant::where('tenantId', $tenantId)->first();
        if (! $tenant || ! $this->invoiceAuth->tenantHasOwnerForSupervisoryOtp($tenant)) {
            return response()->json([
                'message' => 'A business owner must be on file before voiding invoices. Assign an owner to this tenant, then request an approval code.',
            ], 422);
        }

        $request->validate(['supervisor_action_token' => 'required|string']);
        try {
            $this->supervisorOtp->assertValidToken(
                $request->input('supervisor_action_token'),
                $tenantId,
                (int) $user->id,
                $invoice->invoiceId
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);

        $priorStatus = (string) $invoice->status;

        $invoice->status = 'VOID';
        $invoice->voided_at = now();
        $invoice->void_reason = $validated['reason'];
        $invoice->balanceDue = 0;
        $invoice->save();

        $this->recordInvoiceAudit($invoice, 'void', $validated['reason'], [
            'prior_status' => $priorStatus,
        ]);

        $invoice->load(['items', 'currencyDetail', 'tenant', 'customer', 'creator']);

        return response()->json([
            'message' => 'Invoice voided successfully.',
            'invoice' => $invoice,
        ]);
    }

    public function amendInvoiceItems(Request $request, string $invoiceId)
    {
        $tenantId = $request->header('X-Tenant-ID');
        $user = Auth::user();

        if (! $tenantId) {
            return response()->json(['message' => 'Tenant ID is missing'], 400);
        }

        $invoice = Invoice::with('items')->where('invoiceId', $invoiceId)->where('tenantId', $tenantId)->first();

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        if (! $this->invoiceAuth->canAmendInvoiceItems($user, $invoice, $tenantId)) {
            return response()->json([
                'message' => 'You cannot amend line items on this invoice (must be unpaid or overdue, not void, with supervisor or creator access).',
            ], 403);
        }

        $tenant = Tenant::where('tenantId', $tenantId)->first();
        if (! $tenant || ! $this->invoiceAuth->tenantHasOwnerForSupervisoryOtp($tenant)) {
            return response()->json([
                'message' => 'A business owner must be on file before editing invoice lines. Assign an owner to this tenant, then request an approval code.',
            ], 422);
        }

        $request->validate(['supervisor_action_token' => 'required|string']);
        try {
            $this->supervisorOtp->assertValidToken(
                $request->input('supervisor_action_token'),
                $tenantId,
                (int) $user->id,
                $invoice->invoiceId
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.itemDescription' => 'required|string',
            'items.*.amount' => 'required|numeric|min:0',
            'items.*.quantity' => 'required|numeric|integer|min:1',
        ]);

        $prevItems = $invoice->items->map(fn ($i) => $i->only(['itemDescription', 'quantity', 'amount']))->all();

        $invoice->items()->delete();

        foreach ($validated['items'] as $item) {
            $invoice->items()->create([
                'itemDescription' => $item['itemDescription'],
                'quantity' => (int) $item['quantity'],
                'amount' => (float) $item['amount'],
            ]);
        }

        $discountPercentage = (float) ($invoice->discountPercentage ?? 0);
        $taxPercentage = (float) ($invoice->taxPercentage ?? 0);
        $amountPaid = (float) ($invoice->amountPaid ?? 0);

        $totals = $this->invoiceTotals->compute(
            $validated['items'],
            $discountPercentage,
            $taxPercentage,
            $amountPaid
        );

        $invoice->subtotal = $totals['subtotal'];
        $invoice->discountAmount = $totals['discountAmount'];
        $invoice->taxAmount = $totals['taxAmount'];
        $invoice->totalAmount = $totals['totalAmount'];
        $invoice->balanceDue = $totals['balanceDue'];

        if ($amountPaid >= $totals['totalAmount']) {
            $invoice->status = 'PAID';
            $invoice->balanceDue = 0;
        } elseif ($amountPaid > 0) {
            $invoice->status = 'PARTIAL_PAYMENT';
        } else {
            $invoice->status = 'UNPAID';
        }

        $invoice->save();
        $invoice->load('items');

        $this->recordInvoiceAudit($invoice, 'amend_items', $validated['reason'] ?? null, [
            'previous_items' => $prevItems,
            'new_items' => $validated['items'],
        ]);

        $invoice->load(['items', 'currencyDetail', 'tenant', 'customer', 'creator']);

        return response()->json([
            'message' => 'Invoice line items updated.',
            'invoice' => $invoice,
        ]);
    }

//ANALYTICS DATA   /**
public function invoiceStatusBreakdown()
{
    $data = Invoice::query()
        ->withoutGlobalScopes()
        ->selectRaw('status, COUNT(*) as count')
        ->groupBy('status')
        ->get();

    return response()->json($data);
}



public function overdueInvoicesSummary()
{
    $today = Carbon::today();

    $data = [
        '1_7_days' => Invoice::withoutGlobalScopes()
            ->where('status', 'overdue')
            ->whereBetween('dueDate', [
                $today->copy()->subDays(7),
                $today->copy()->subDay()
            ])->count(),

        '8_30_days' => Invoice::withoutGlobalScopes()
            ->where('status', 'overdue')
            ->whereBetween('dueDate', [
                $today->copy()->subDays(30),
                $today->copy()->subDays(8)
            ])->count(),

        '31_plus_days' => Invoice::withoutGlobalScopes()
            ->where('status', 'overdue')
            ->where('dueDate', '<', $today->copy()->subDays(30))
            ->count(),
    ];

    return response()->json($data);
}


public function currencyDistribution()
{
    $data = Invoice::query()
        ->withoutGlobalScopes()
        ->join('currencies', 'invoices.currency', '=', 'currencies.currencyId')
        ->selectRaw('
            currencies.currencyCode as currency,
            SUM(invoices.amountPaid) as total
        ')
        ->groupBy('currencies.currencyCode')
        ->orderByDesc('total')
        ->get();

    return response()->json($data);
}


public function topTenants()
{
    $data = Invoice::query()
        ->withoutGlobalScopes()
        ->join('tenants', 'invoices.tenantId', '=', 'tenants.tenantId')
        ->selectRaw('
            tenants.tenantName,
            SUM(invoices.amountPaid) as revenue
        ')
        ->groupBy('tenants.tenantId', 'tenants.tenantName')
        ->orderByDesc('revenue')
        ->limit(5)
        ->get();

    return response()->json($data);
}


public function revenueTrends()
{
    $data = Invoice::query()
        ->withoutGlobalScopes()
        ->where('status', 'paid')
        ->selectRaw('
            DATE_FORMAT(created_at, "%Y-%m") as period,
            SUM(amountPaid) as revenue
        ')
        ->groupBy('period')
        ->orderBy('period')
        ->get();

    return response()->json($data);
}

public function paymentMethodBreakdown()
{
    $data = Invoice::query()
        ->withoutGlobalScopes()
        ->selectRaw('paymentMethod, COUNT(*) as count')
        ->groupBy('paymentMethod')
        ->get();

    return response()->json($data);
}


}
