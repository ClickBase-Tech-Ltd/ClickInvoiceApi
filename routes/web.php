<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LearningController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\WebhookController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application.
|
*/

Route::get('/', function () {
    return view('welcome');
});

// Serve files
Route::get('/posts/{filename}', function ($filename) {
    $path = storage_path('app/public/posts/' . $filename);
    if (!file_exists($path)) abort(404);
    return response()->file($path);
});

Route::get('/tenant-logos/{filename}', function ($filename) {
    $path = storage_path('app/public/tenant-logos/' . $filename);
    if (!file_exists($path)) abort(404);
    return response()->file($path);
});

Route::get('/signatures/{filename}', function ($filename) {
    $path = storage_path('app/public/signatures/' . $filename);
    if (!file_exists($path)) abort(404);
    return response()->file($path);
});

Route::get('/profile-images/{filename}', function ($filename) {
    $path = storage_path('app/public/profile-images/' . $filename);
    if (!file_exists($path)) abort(404);
    return response()->file($path);
});

Route::get('/cover-images/{filename}', function ($filename) {
    $path = storage_path('app/public/cover-images/' . $filename);
    if (!file_exists($path)) {
        abort(404);
    }
    return response()->file($path);
});


Route::post('/flutterwave/webhook', [WebhookController::class, 'handle'])->withoutMiddleware('csrf');
Route::get('/subscription/redirect', [SubscriptionController::class, 'handleRedirect']);

// Preview routes for email templates (development only)
Route::get('/preview/support-ticket', function () {
    $user = (object)['name' => 'Emmanuel', 'email' => 'oijileo@gmail.com'];
    $ticket = (object)['ticketId' => 17, 'subject' => 'Withdrawal', 'message' => "8068888950\nOpay\nEmmanuel Leonard Oiji\nNigeria\nTel 07016716409"];
    return view('emails.support_ticket_created', compact('user','ticket'));
});

Route::get('/preview/support-reply', function () {
    $user = (object)['name' => 'Emmanuel', 'email' => 'oijileo@gmail.com'];
    $ticket = (object)['ticketId' => 17, 'subject' => 'Withdrawal'];
    $reply = (object)['message' => "8068888950\nOpay\nEmmanuel Leonard Oiji\nNigeria\nTel 07016716409"];
    return view('emails.support_reply_received', compact('user','ticket','reply'));
});

Route::get('/preview/support-confirmation', function () {
    $user = (object)['name' => 'Emmanuel', 'email' => 'oijileo@gmail.com'];
    $ticket = (object)['ticketId' => 17, 'subject' => 'Withdrawal', 'message' => "8068888950\nOpay\nEmmanuel Leonard Oiji\nNigeria\nTel 07016716409"];
    return view('emails.support_confirmation', compact('user','ticket'));
});

// Preview welcome email (renders the actual `WelcomeEmail` mailable)
Route::get('/preview/welcome', function () {
    $user = new \App\Models\User();
    $user->name = 'Emeka';
    $user->email = 'emeka@example.test';

    return new \App\Mail\WelcomeEmail($user);
});

// Preview invoice email
Route::get('/preview/invoice', function () {
    $customerName = 'Ada Okafor';
    $invoice = (object)[];
    $invoice->userGeneratedInvoiceId = 'INV-2026-001';
    $invoice->invoiceId = '1001';
    $invoice->projectName = 'Website redesign';
    $invoice->invoiceDate = now()->toDateString();
    $invoice->status = 'unpaid';
    $invoice->tenant = (object)['tenantName' => 'Acme Ltd.'];

    return view('emails.invoice', compact('customerName','invoice'));
});

// Preview receipt PDF view (renders HTML view used for PDF)
Route::get('/preview/receipt', function () {
    $userGeneratedReceiptId = 'RCPT-2026-0001';
    $receiptId = $userGeneratedReceiptId;
    $receiptDate = now()->toDateString();
    $updated_at = now();
    $logoUrl = 'https://app.clickinvoice.app/images/logo/logo.svg';
    $companyName = 'ClickInvoice';
    $companyAddress = "1 Industrial Ave\nLagos, Nigeria";
    $companyEmail = 'billing@clickinvoice.app';
    $companyPhone = '+234 700 000 0000';
    $companyTaxId = 'TIN-123456';
    $projectName = 'Website redesign';
    $customerName = 'Ada Okafor';
    $customerAddress = "15 Broad St\nLagos, Nigeria";
    $customerEmail = 'ada.okafor@example.test';
    $customerPhone = '+234 800 000 0000';
    $currencySymbol = '₦';
    $items = [
        ['description' => 'Design work', 'quantity' => 1, 'amount' => 150000],
        ['description' => 'Hosting (1 year)', 'quantity' => 1, 'amount' => 20000],
    ];
    $subtotal = 170000;
    $discountPercentage = 0;
    $discountAmount = 0;
    $subtotalAfterDiscount = $subtotal - $discountAmount;
    $taxPercentage = 0;
    $taxAmount = 0;
    $totalAmount = $subtotalAfterDiscount + $taxAmount;
    $amountPaid = $totalAmount;
    $accountName = 'ClickInvoice Account';
    $accountNumber = '1234567890';
    $bank = 'GTBank';
    $signatureUrl = null;

    return view('pdf.receipt', compact(
        'userGeneratedReceiptId','receiptId','receiptDate','updated_at','logoUrl','companyName','companyAddress','companyEmail','companyPhone','companyTaxId','projectName','customerName','customerAddress','customerEmail','customerPhone','currencySymbol','items','subtotal','discountPercentage','discountAmount','subtotalAfterDiscount','taxPercentage','taxAmount','totalAmount','amountPaid','accountName','accountNumber','bank','signatureUrl'
    ));
});
