<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Carbon\Carbon;

class InvoiceController extends Controller
{
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
            'invoiceId' => 'required|unique:invoices,invoiceId',
            'projectName' => 'nullable|string|max:255',
            'invoiceDate' => 'nullable|date',
            'dueDate' => 'nullable|date',
            'currency' => 'nullable|exists:currencies,currencyId',
            'tenantId' => 'nullable|exists:tenants,tenantId',
            'createdBy' => 'nullable|exists:users,id',
            'taxPercentage' => 'nullable|numeric|min:0',
            'items' => 'required|array|min:1',
            'items.*.itemDescription' => 'required|string',
            'items.*.amount' => 'required|numeric|min:0',
            'items.*.quantity' => 'required|numeric|min:0',
            'items.*.discountAmount' => 'required|numeric|min:0',
            'amountPaid' => 'nullable|numeric|min:0',
        ]);

        // Calculate total of items
        $totalAmount = collect($request->items)->sum(fn($item) => $item['amount']);

        // Apply tax if provided
        $tax = $request->input('taxPercentage', 0);
        $totalAmountWithTax = $tax > 0 ? $totalAmount * (1 + $tax / 100) : $totalAmount;

        // Determine amountPaid and balanceDue
        $amountPaid = $request->input('amountPaid', 0);
        $balanceDue = $totalAmountWithTax - $amountPaid;

        $getCurrency = Tenant::where('tenantId', $tenantId)->first();
        // Create invoice
        $invoice = Invoice::create(array_merge(
            $request->only([
                'invoiceId',
                'userGeneratedInvoiceId',
                'projectName',
                'invoiceDate',
                'dueDate',
                'invoicePassword',
                'notes',
                'currency',
                'accountName',
                'accountNumber',
                'bank',
                'taxPercentage',
                'customerId'
            ]),
            [
                'amountPaid' => $amountPaid,
                'balanceDue' => $balanceDue,
                'tenantId' => $tenantId,
                'createdBy' => auth()->id() ,
                'currency' => $getCurrency->currency,
            ]
        ));

        // Create invoice items
        foreach ($request->items as $item) {
            $invoice->items()->create([
                'itemDescription' => $item['itemDescription'],
                'amount' => $item['amount'],
                'discountAmount' => $item['discountAmount'],
                'quantity' => $item['quantity'],
            ]);
        }

        return response()->json([
            'message' => 'Invoice created successfully',
            'invoice' => $invoice->load('items')
        ], 201);
    }

    /**
     * Get all invoices for the authenticated user.
     */
    public function getUserInvoices(Request $request)
    {
        $tenantId = $request->header('X-Tenant-ID');
        $userId = Auth::id();

        $invoices = Invoice::with('items', 'currencyDetail', 'customer')
            ->where('createdBy', $userId)
            ->where('tenantId', $tenantId)
            ->get();

        return response()->json($invoices);
    }


    public function getUserReceipts(Request $request)
    {
        $tenantId = $request->header('X-Tenant-ID');
        $userId = Auth::id();

        $invoices = Invoice::with('items', 'currencyDetail', 'customer')
            ->where('createdBy', $userId)
            ->where('tenantId', $tenantId)
            ->where('status', 'PAID')
            ->orWhere('status', 'PARTIAL_PAYMENT')
            ->get();

        return response()->json($invoices);
    }

     public function getLast5UserInvoices(Request $request)
{
    $tenantId = $request->header('X-Tenant-ID');
    $userId = Auth::id();

    if (!$tenantId) {
        return response()->json([
            'message' => 'Tenant ID is missing'
        ], 400);
    }

    $invoices = Invoice::with([
            'items',
            'currencyDetail',
            'customer',
        ])
        ->where('createdBy', $userId)
        ->where('tenantId', $tenantId)
        ->latest()   // orders by created_at desc
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
    // IMPORTANT: disable tenant scope if it exists
    Invoice::withoutGlobalScopes();

    $summaries = Invoice::query()
        ->leftJoin('currencies', 'invoices.currency', '=', 'currencies.currencyId')
        ->selectRaw('
            currencies.currencyCode AS currency_code,
            currencies.currencySymbol AS currency_symbol,
            currencies.country AS country,
            currencies.currencySymbol AS currency_symbol,
            SUM(invoices.amountPaid)  AS collected,
            SUM(invoices.balanceDue) AS outstanding
        ')
        ->groupBy(
            'currencies.currencyCode',
            'currencies.currencySymbol',
            'currencies.country',
        )
        ->get();

    return response()->json(
        $summaries->map(fn ($row) => [
            'currency_code'   => $row->currency_code,
            'currency_symbol' => $row->currency_symbol
                ?? $this->getFallbackSymbol($row->currency_code),
            'country'         => $row->country,
            'collected'       => (float) $row->collected,
            'outstanding'     => (float) $row->outstanding,
        ])
    );
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
        $userId = Auth::id();

        $invoice = Invoice::with('items', 'currencyDetail', 'tenant', 'customer', 'creator')
            ->where('createdBy', $userId)
            ->where('invoiceId', $invoiceId)
            ->where('tenantId', $tenantId)
            ->get();

        return response()->json($invoice);
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
        $userId = Auth::id();

        $invoice = Invoice::with('items', 'currencyDetail', 'tenant', 'customer', 'creator')
            ->where('createdBy', $userId)
            ->where('receiptId', $receiptId)
            ->where('tenantId', $tenantId)
            ->get();

        return response()->json($invoice);
    }

public function getInvoiceAndReceiptsByCustomerId(Request $request, $customerId)
    {
        $tenantId = $request->header('X-Tenant-ID');
        $userId = Auth::id();

        $invoice = Invoice::with('items', 'currencyDetail', 'tenant', 'customer')
            ->where('createdBy', $userId)
            ->where('customerId', $customerId)
            ->where('tenantId', $tenantId)
            ->get();

        return response()->json($invoice);
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

        $invoices = Invoice::with('items', 'currencyDetail', 'tenant', 'customer')
            ->where('createdBy', $userId)
            ->where('customerId', $customerId)
            ->where('status', 'UNPAID')
            ->where('tenantId', $tenantId)
            ->get();

        return response()->json($invoices);
    }


public function getReceiptsForCustomer(Request $request, $customerId)
{
    $tenantId = $request->header('X-Tenant-ID');
    $userId = Auth::id();

    $receipts = Invoice::with(['items', 'currencyDetail', 'tenant', 'customer'])
        ->where('createdBy', $userId)
        ->where('customerId', $customerId)
        ->whereIn('status', ['PAID', 'PARTIAL_PAYMENT'])
        ->where('tenantId', $tenantId)
        ->get();

    return response()->json($receipts);
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

    $validated = $request->validate([
        'status' => 'required|string',
        'amountPaid' => 'nullable|numeric|min:0'
    ]);

    $status = strtoupper($validated['status']);

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
