<?php
namespace App\Http\Controllers;

use App\Models\Plans;
use App\Services\PaystackClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class PlansController extends Controller
{
public function store(Request $request, PaystackClient $paystack)
{
    if (!$this->isBillingAdmin()) {
        return response()->json(['message' => 'Billing administrator access is required.'], 403);
    }

    $validatedData = $request->validate([
        'planName'      => 'required|string|max:255',
        'price'         => 'required|numeric|min:0.01',
        'currency'      => 'required|integer|exists:currencies,currencyId',
        'features'      => 'required|string',
        'isPopular'     => 'required|boolean',
        'tenantLimit'   => 'required|integer',
        'invoiceLimit'  => 'required|integer',
    ]);

    
    if (!$paystack->isConfiguredForCurrentEnvironment()) {
        return response()->json(['message' => 'Paystack is not configured.'], 503);
    }

    $getCurrency = DB::table('currencies')->where('currencyId', $validatedData['currency'])->first();
    $response = $paystack->createPlan(
        $validatedData['planName'],
        (int) round(((float) $validatedData['price']) * 100),
        $getCurrency->currencyCode,
        'monthly'
    );

    $planCode = $response->json('data.plan_code');
    if (!$response->successful() || !$planCode) {
        return response()->json(['message' => 'Paystack could not create this subscription plan.'], 502);
    }

    $planCodeField = app()->environment('production') ? 'paystackPlanCode' : 'paystackTestPlanCode';
    $validatedData[$planCodeField] = $planCode;
    $plan = Plans::create($validatedData);

    return response()->json([
        'message' => 'Plan created successfully',
        'plan' => $plan,
    ], 201);
}


public function update(Request $request, $planId, PaystackClient $paystack)
{
    if (!$this->isBillingAdmin()) {
        return response()->json(['message' => 'Billing administrator access is required.'], 403);
    }

    $validatedData = $request->validate([
        'planName'      => 'sometimes|required|string|max:255',
        'price'         => 'sometimes|required|numeric|min:0.01',
        'currency'      => 'sometimes|required|integer|exists:currencies,currencyId',
        'features'      => 'sometimes|required|string',
        'isPopular'     => 'sometimes|required|boolean',
        'tenantLimit'   => 'sometimes|required|integer',
        'invoiceLimit'  => 'sometimes|required|integer',
    ]);

    $plan = Plans::with('currency_detail')->findOrFail($planId);
    $planCodeField = app()->environment('production') ? 'paystackPlanCode' : 'paystackTestPlanCode';
    $billingTermsChanged =
        (isset($validatedData['planName']) && $validatedData['planName'] !== $plan->planName) ||
        (isset($validatedData['price']) && (float) $validatedData['price'] !== (float) $plan->price) ||
        (isset($validatedData['currency']) && (int) $validatedData['currency'] !== (int) $plan->currency);

    if (!$plan->{$planCodeField} || $billingTermsChanged) {
        if (!$paystack->isConfiguredForCurrentEnvironment()) {
            return response()->json(['message' => 'Paystack is not configured.'], 503);
        }

        $currency = isset($validatedData['currency'])
            ? DB::table('currencies')->where('currencyId', $validatedData['currency'])->first()
            : $plan->currency_detail;
        $response = $paystack->createPlan(
            $validatedData['planName'] ?? $plan->planName,
            (int) round(((float) ($validatedData['price'] ?? $plan->price)) * 100),
            $currency->currencyCode,
            'monthly'
        );

        $planCode = $response->json('data.plan_code');
        if (!$response->successful() || !$planCode) {
            return response()->json(['message' => 'Paystack could not update this subscription plan.'], 502);
        }

        $validatedData[$planCodeField] = $planCode;
    }

    $plan->update($validatedData);

    return response()->json([
        'message' => 'Plan updated successfully',
        'plan' => $plan->fresh(),
    ]);
}

private function isBillingAdmin(): bool
{
    $role = strtoupper(trim((string) auth()->user()?->user_role?->roleName));

    return in_array($role, ['ADMIN', 'SUPER_ADMIN', 'SUPERADMIN'], true);
}
}