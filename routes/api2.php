<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CancerController;
use App\Http\Controllers\BeneficiariesController;
use App\Http\Controllers\EquipmentController;
use App\Http\Controllers\StateController;
use App\Http\Controllers\LgaController;
use App\Http\Controllers\ProductsController;
use App\Http\Controllers\RolesController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\MinistryController;
use App\Http\Controllers\AgentsController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SuggestedFollowersController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\ProductRequestController;
use App\Http\Controllers\TransactionsController;
use App\Http\Controllers\ProjectsController;
use App\Http\Controllers\HubsController;
use App\Http\Controllers\MSPsController;
use App\Http\Controllers\FarmersController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\ServicesController;
use App\Http\Controllers\UserEmailController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerController as ControllersCustomerController;
use App\Http\Controllers\ResetPasswordController;
use App\Http\Controllers\ForgotPasswordController;
use App\Http\Controllers\OtpController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\RecruitmentJobApplicationsController;
use App\Http\Controllers\LearningController;
use App\Http\Controllers\LessonController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\InstructorCourseController;
use App\Http\Controllers\InstructorModuleController;
use App\Http\Controllers\InstructorLessonController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceSupervisorOtpController;
use App\Http\Controllers\InvoicePdfController;
use App\Http\Controllers\TenantsController;
use App\Models\Currency;
use App\Models\PaymentGateway;
use App\Models\Plans;
use Tymon\JWTAuth\Claims\Custom;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\PlansController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public routes
Route::post('/resend-otp', [OtpController::class, 'resendOtp']);
Route::post('/verify-otp', [OtpController::class, 'verifyOtp']);
Route::post('/setup-password', [AuthController::class, 'setupPassword']);

Route::post('/signup', [AuthController::class, 'signup2']);
Route::post('/signin', [AuthController::class, 'signin']);
Route::post('/logout', [AuthController::class, 'logout']);
Route::post('/refresh', [AuthController::class, 'refresh']);
Route::get('/users/profile', [AuthController::class, 'profile'])->middleware('auth.jwt');
Route::get('/roles', [RolesController::class, 'index']);
Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLink']);
Route::post('/reset-password', [ResetPasswordController::class, 'reset'])->name('api.password.reset');

Route::get('/currencies', function(){
    $currencies = Currency::orderBy('currencyId')->get()->makeHidden([ 'created_at', 'updated_at', 'deleted_at']);
    return response()->json($currencies);
});

Route::get('/payment-gateways', function(){
    $gateways = PaymentGateway::orderBy('gatewayId')->get()->makeHidden([ 'created_at', 'updated_at', 'deleted_at']);
    return response()->json($gateways);
});

Route::get('/subscription-plans', function(){
    $plans = Plans::with('currency_detail')->orderBy('planId')->get()->makeHidden([ 'created_at', 'updated_at', 'deleted_at']);
    return response()->json($plans);
});

Route::get('/invoices/admin-summary', [InvoiceController::class, 'adminInvoiceSummary']);
Route::get('/admin/invoice-status-breakdown', [InvoiceController::class, 'invoiceStatusBreakdown']);
Route::get('/admin/top-tenants', [InvoiceController::class, 'topTenants']);
Route::get('/admin/revenue-trends', [InvoiceController::class, 'revenueTrends']);
Route::get('/admin/payment-method-breakdown', [InvoiceController::class, 'paymentMethodBreakdown']);
Route::get('/admin/overdue-invoices-summary', [InvoiceController::class, 'overdueInvoicesSummary']);
Route::get('/admin/currency-distribution', [InvoiceController::class, 'currencyDistribution']);

Route::get('/users', [UsersController::class, 'index']);
Route::middleware(['auth.jwt', 'tenant'])->group(function () {

    Route::get('/user', function () {
        $user = auth()->user(); // Use the 'api' guard for JWT

        return response()->json([
            'user' => [
                // 'id' => (string) $user->id,
                'id' => $user->id,
                'full_name' => trim($user->firstName . ' ' . $user->lastName . ' ' . ($user->otherNames ?? '')),
                'role' => $user->user_role->roleName ?? null,
                'phoneNumber' => $user->phoneNumber,
                'email' => $user->email,
                'default_tenant' => $user->default_tenant,
                'tenantId' => $user->currently_active_tenant->tenantId ?? '',
                'user_plan' => $user->current_plan->planName ?? "",
            ]
        ]);
    });

// Route::get('/plans', function(){
//     $plans = Plans::orderBy('planId')->with('currency_detail', 'isSubscribed')->get();
//     return response()->json($plans);
// });

Route::get('/plans', function () {
    $user = auth()->user();

    $plans = Plans::orderBy('planId')
        ->with('currency_detail')
        ->get()
        ->map(function ($plan) use ($user) {
            $plan->is_subscribed = $user
                ? $plan->subscriptions()
                    ->where('userId', $user->id)
                    ->where('status', 'active')
                    ->exists()
                : false;

            return $plan;
        });

    return response()->json($plans);
    });



    Route::post('/subscription-plans', [PlansController::class, 'store']);
    Route::patch('/subscription-plans/{planId}', [PlansController::class, 'update']);

    Route::post('/currencies', function () {
        $validatedData = request()->validate([
            'currencyName' => 'required|string|max:255',
            'currencyCode' => 'required|string|max:10',
            'currencySymbol' => 'required|string|max:10',
            'country' => 'required|string|max:255',
        ]);
    
        $currency = Currency::create($validatedData);
    
        return response()->json(['message' => 'Currency created successfully', 'currency' => $currency], 201);
    });

    Route::patch('/currencies/{currencyId}', function ($currencyId) {
        
        $validatedData = request()->validate([
            'currencyName' => 'sometimes|string|max:255',
            'currencyCode' => 'sometimes|string|max:10',
            'currencySymbol' => 'sometimes|string|max:10',
            'country' => 'sometimes|string|max:255',
        ]);
        Currency::where('currencyId', $currencyId)->update($validatedData);
        return response()->json(['message' => 'Currency updated successfully']);
    });

    Route::post('/payment-gateways', function () {
        $validatedData = request()->validate([
            'paymentGatewayName' => 'required|string|max:255',
            'url' => 'nullable|string',
        ]);
    
        $gateway = PaymentGateway::create($validatedData);
    
        return response()->json(['message' => 'Payment gateway created successfully', 'gateway' => $gateway], 201);
    }); 

    Route::patch('/payment-gateways/{gatewayId}', function ($gatewayId) {
        
        $validatedData = request()->validate([
            'paymentGatewayName' => 'sometimes|string|max:255',
            'url' => 'sometimes|string',
        ]);
        PaymentGateway::where('gatewayId', $gatewayId)->update($validatedData);
        return response()->json(['message' => 'Payment gateway updated successfully']);
    });
  
    Route::get('profile', [UsersController::class, 'userProfile']);
    Route::patch('/profile', [UsersController::class, 'updateUser']);
    Route::patch('/profile/password', [UsersController::class, 'updatePassword']);

    Route::put('/tenants/{tenantId}', [TenantsController::class, 'update']);
    Route::patch('/tenants/{tenantId}/status', [TenantsController::class, 'toggleTenantStatus']);

    Route::patch('/tenants/{tenantId}/set-default', [TenantsController::class, 'setDefaultTenant']);

    // User profile routes
    Route::get('profile/biodata', [UsersController::class, 'userBiodataProfile']);
    Route::get('profile/education', [UsersController::class, 'userEducationProfile']);
    Route::get('profile/experience', [UsersController::class, 'userExperienceProfile']);
    Route::get('profile/skills', [UsersController::class, 'userSkillsProfile']);
    Route::get('profile/drivers-license', [UsersController::class, 'userDriversLicenseProfile']);

    Route::post('profile/biodata', [UsersController::class, 'storeUserBiodata']);
    Route::post('profile/education', [UsersController::class, 'storeUserEducation']);
    Route::post('profile/experience', [UsersController::class, 'storeUserExperience']);
    Route::post('profile/skills', [UsersController::class, 'storeUserSkills']);
    Route::post('profile/drivers-license', [UsersController::class, 'storeUserDriversLicense']);

    Route::delete('profile/education/{id}', [UsersController::class, 'deleteUserEducation']);
    Route::delete('profile/experience/{id}', [UsersController::class, 'deleteUserExperience']);
    Route::delete('profile/skills/{id}', [UsersController::class, 'deleteUserSkills']);
    Route::delete('profile/drivers-license/{id}', [UsersController::class, 'deleteUserDriversLicense']);

    Route::post('profile/upload-image', [UsersController::class, 'uploadProfileImage']);
    Route::post('profile/upload-cover-image', [UsersController::class, 'uploadCoverImage']);

    // Tenants
    Route::get('tenants', [TenantsController::class, 'index']);
    Route::post('tenants', [TenantsController::class, 'store']);
    Route::get('tenants/user-tenants', [TenantsController::class, 'myTenants']);
    // Route::post('/tenants/{tenantId}', [TenantsController::class, 'update']);
   
    Route::get('customers/tenant', [CustomerController::class, 'getTenantCustomers']);
    Route::post('customers/tenant', [CustomerController::class, 'storeTenantCustomer']);
    Route::get('customers/admin', [CustomerController::class, 'index']);



    // Create a new invoice
    Route::get('/invoices/latest', [InvoiceController::class, 'getLast5UserInvoices']);
    Route::get('/invoices/summary', [InvoiceController::class, 'invoiceSummary']);
    
    Route::get('/invoices/admin', [InvoiceController::class, 'index']);
    Route::post('/invoices', [InvoiceController::class, 'store']);
    Route::get('/invoices/{invoiceId}/admin', [InvoiceController::class, 'getInvoiceByInvoiceIdForAdmin']);

    // Get all invoices for logged-in user
    Route::get('/invoices', [InvoiceController::class, 'getUserInvoices']);
    Route::get('/receipts', [InvoiceController::class, 'getUserReceipts']);



    // Get a single invoice by tenant ID
    Route::get('/invoices/tenant/{tenantId}', [InvoiceController::class, 'getInvoiceByTenant']);

    // Get a single invoice by Invoice ID
    Route::get('/invoices/{invoiceId}', [InvoiceController::class, 'getInvoiceByInvoiceId']);
    Route::get('/receipts/{receiptId}', [InvoiceController::class, 'getReceiptByReceiptId']);
    Route::patch('/invoices/{invoiceId}/status', [InvoiceController::class, 'updateInvoiceStatus']);
    Route::get('/invoices/{invoiceId}/capabilities', [InvoiceController::class, 'invoiceCapabilities']);
    Route::post('/invoices/{invoiceId}/void', [InvoiceController::class, 'voidInvoice']);
    Route::patch('/invoices/{invoiceId}/items', [InvoiceController::class, 'amendInvoiceItems']);
    Route::post('/invoices/{invoiceId}/supervisor-otp/request', [InvoiceSupervisorOtpController::class, 'requestOtp']);
    Route::post('/invoices/{invoiceId}/supervisor-otp/verify', [InvoiceSupervisorOtpController::class, 'verifyOtp']);

    Route::get('/customers', [CustomerController::class, 'getTenantCustomers']);
    Route::get('/customers/{customerId}/invoices-and-receipt', [InvoiceController::class, 'getInvoiceAndReceiptsByCustomerId']);
    Route::post('/customers/{customerId}/send-email', [CustomerController::class, 'sendSingleEmail']);
    Route::post('/customers/broadcast-email', [CustomerController::class, 'broadcastEmail']);

    Route::get('/invoices/{customerId}/invoices', [InvoiceController::class, 'getInvoicesForCustomer']);
    Route::get('/invoices/{customerId}/receipts', [InvoiceController::class, 'getReceiptsForCustomer']);
    // Route::get('/invoices/{invoiceId}', [InvoiceController::class, 'show'])
    // ->where('invoiceId', '[A-Za-z0-9\-]+');


        // PDF routes
    // Route::prefix('invoices')->group(function () {
    //     Route::get('/{id}/pdf', [InvoicePdfController::class, 'download']);
    //     Route::get('/{id}/stream-pdf', [InvoicePdfController::class, 'stream']);
    //     Route::get('/{id}/generate-pdf', [InvoicePdfController::class, 'generate']);
    //     Route::post('/{id}/send-email', [InvoicePdfController::class, 'sendEmail']);
    // });

    Route::get('/subscribers', [SubscriptionController::class, 'index']);

    // Support Routes
    Route::get('/support/tickets', [SupportController::class, 'index']);
    Route::post('/support/tickets', [SupportController::class, 'store']);
    Route::post('/support/tickets/{ticketId}/reply', [SupportController::class, 'reply']);
    Route::post('/support/tickets/{ticketId}/admin-reply', [SupportController::class, 'adminReply']);
    Route::get('/support/tickets/all', [SupportController::class, 'indexAdmin']);
    Route::patch('/support/tickets/{ticketId}/status', [SupportController::class, 'updateTicketStatus']);

    Route::post('/subscribe/{planId}', [SubscriptionController::class, 'create']); // Add auth
    Route::patch('/subscriptions/{subscriptionId}/activate', [SubscriptionController::class, 'activate']);
    Route::patch('/subscriptions/{subscriptionId}/deactivate', [SubscriptionController::class, 'deactivate']);
    Route::patch('/subscriptions/{subscriptionId}/expire', [SubscriptionController::class, 'expire']);
    Route::post('/subscriptions/assign-manual', [SubscriptionController::class, 'assignManual']);
    Route::patch('/subscriptions/bulk-action', [SubscriptionController::class, 'bulkAction']);

    Route::get('/users/{id}/profile', [UsersController::class, 'profile']);

    Route::post('/users/{id}/send-email', [UserEmailController::class, 'sendSingle']);
    Route::post('/users/broadcast-email', [UserEmailController::class, 'broadcast']);
});

Route::prefix('invoices')->group(function () {
    Route::get('/{id}/pdf', [InvoicePdfController::class, 'download']);
    Route::get('/{id}/stream-pdf', [InvoicePdfController::class, 'stream']);
    Route::get('/{id}/generate-pdf', [InvoicePdfController::class, 'generate']);
    Route::post('/{id}/send-email', [InvoicePdfController::class, 'sendEmail']);
});

Route::prefix('receipts')->group(function () {
    Route::get('/{id}/pdf', [InvoicePdfController::class, 'downloadReceipt']);
    Route::get('/{id}/stream-pdf', [InvoicePdfController::class, 'stream']);
    Route::get('/{id}/generate-pdf', [InvoicePdfController::class, 'generate']);
    Route::post('/{id}/send-email', [InvoicePdfController::class, 'sendReceiptEmail']);
});
