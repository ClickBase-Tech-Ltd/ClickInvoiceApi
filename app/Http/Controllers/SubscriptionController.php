<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Plans;
use App\Models\Subscription;
use App\Models\SubscriptionEmailOutbox;
use App\Models\Payment;
use App\Models\User; // Assuming auth
use App\Mail\UserNotificationMail;
use App\Services\PaystackClient;
use App\Services\PaystackSubscriptionService;
use App\Services\SubscriptionEmailOutboxDelivery;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SubscriptionController extends Controller
{

    public function index(Request $request)
    {
        if (!$this->isBillingAdmin()) {
            return response()->json(['message' => 'Billing administrator access is required.'], 403);
        }

        if ($request->boolean('summary')) {
            $subscriptions = Subscription::query()
                ->select(['subscriptionId', 'status', 'created_at', 'startDate', 'metadata', 'planId'])
                ->with(['plan' => function ($query) {
                    $query->select(['planId', 'price']);
                }])
                ->get();

            return response()->json(['subscriptions' => $subscriptions]);
        }

        $subscriptions = Subscription::with('user', 'plan.currency_detail')->get();

        return response()->json([
            'subscriptions' => $subscriptions,
        ]);
    }

    public function mySubscriptions(Request $request)
    {
        $user = auth()->user();

        $subscriptions = Subscription::with('user', 'plan.currency_detail')
            ->where('userId', $user->id)
            ->get();

        return response()->json([
            'subscriptions' => $subscriptions,
        ]);
    }

    public function verifyPaystackPayment(Request $request, PaystackClient $paystack, PaystackSubscriptionService $subscriptions)
    {
        $validated = $request->validate([
            'reference' => 'required|string|max:100',
        ]);

        $payment = Payment::where('provider', 'paystack')
            ->where('providerReference', $validated['reference'])
            ->where('userId', auth()->id())
            ->first();

        if (!$payment) {
            return response()->json(['status' => 'not_found'], 404);
        }

        return response()->json($subscriptions->verifyAndApply($validated['reference'], $paystack));
    }

    public function create(Request $request, $planId, PaystackClient $paystack)
    {
        $user = auth()->user();

        // --- Guard: plan must exist ---
        $plan = Plans::with('currency_detail')->where('planId', $planId)->first();
        if (!$plan) {
            return response()->json(['error' => 'Plan not found'], 404);
        }

        if (!$paystack->isConfiguredForCurrentEnvironment()) {
            $requiredMode = app()->environment('production') ? 'Live' : 'Test';

            return response()->json([
                'error' => "Paystack {$requiredMode} credentials are required for this environment. No payment was started.",
                'code' => 'paystack_environment_mismatch',
            ], 503);
        }

        $planCode = $plan->paystackPlanCodeForCurrentEnvironment();
        if (!$planCode) {
            return response()->json(['error' => 'This plan is not configured for Paystack subscription billing. Please contact support.'], 400);
        }

        // --- Guard: currency relation must be loaded ---
        if (!$plan->currency_detail) {
            Log::error('Plan currency_detail missing', ['planId' => $planId]);
            return response()->json(['error' => 'Plan currency is not configured. Please contact support.'], 500);
        }

        if (!config('services.paystack.secret_key')) {
            Log::error('Paystack secret key is not configured.');
            return response()->json(['error' => 'Payment gateway not configured. Please contact support.'], 500);
        }

        // Ignore stale active rows whose billing period has already ended.
        $hasCurrentPlan = Subscription::query()
            ->where('userId', $user->id)
            ->where('planId', $plan->planId)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('startDate')->orWhere('startDate', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('endDate')->orWhere('endDate', '>', now());
            })
            ->where(function ($query) {
                $query->whereNull('nextBillingDate')->orWhere('nextBillingDate', '>', now());
            })
            ->exists();

        if ($hasCurrentPlan) {
            return response()->json(['error' => 'You already have an active subscription on this plan'], 400);
        }

        $reference = 'ci_sub_' . Str::uuid()->toString();
        $currency = strtoupper($plan->currency_detail->currencyCode);
        $amountMinor = (int) round(((float) $plan->price) * 100);
        $frontendUrl = app()->environment(['local', 'testing'])
            ? 'http://localhost:3002'
            : rtrim((string) config('clickinvoice.app_url'), '/');
        $callbackUrl = $frontendUrl . '/dashboard/subscription/success?reference=' . urlencode($reference);

        $subscription = null;
        $payment = null;

        try {
            [$subscription, $payment] = DB::transaction(function () use ($user, $plan, $reference, $currency) {
                $subscription = Subscription::create([
                    'userId' => $user->id,
                    'planId' => $plan->planId,
                    'provider' => 'paystack',
                    'status' => 'pending',
                ]);

                $payment = Payment::create([
                    'subscriptionId' => $subscription->subscriptionId,
                    'provider' => 'paystack',
                    'providerReference' => $reference,
                    'userId' => $user->id,
                    'amount' => $plan->price,
                    'currency' => $currency,
                    'status' => 'pending',
                ]);

                return [$subscription, $payment];
            });

            $response = $paystack->initializeSubscription(
                $user->email,
                $amountMinor,
                $currency,
                $planCode,
                $reference,
                $callbackUrl,
                [
                    'subscription_id' => $subscription->subscriptionId,
                    'payment_id' => $payment->id,
                    'user_id' => $user->id,
                    'plan_id' => $plan->planId,
                ]
            );

            $authorizationUrl = $response->json('data.authorization_url');
            if (!$response->successful() || !$authorizationUrl) {
                $subscription->update(['status' => 'failed']);
                $payment->update(['status' => 'failed']);
                Log::warning('Paystack subscription checkout initialization failed', [
                    'subscription_id' => $subscription->subscriptionId,
                    'provider_status' => $response->status(),
                ]);

                return response()->json([
                    'error' => 'We could not start checkout. Please try again or contact support.',
                ], 502);
            }

            return response()->json([
                'authorization_url' => $authorizationUrl,
                'reference' => $reference,
            ]);
        } catch (\Throwable $exception) {
            if ($subscription && $payment) {
                $subscription->update(['status' => 'failed']);
                $payment->update(['status' => 'failed']);
            }

            Log::error('Paystack subscription checkout error', [
                'plan_id' => $planId,
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'error' => 'We could not start checkout. Please try again or contact support.',
            ], 502);
        }
    }


    public function cancel(Request $request)
{
        $subscription = Subscription::query()
            ->where('userId', auth()->id())
            ->where('status', 'active')
            ->latest('subscriptionId')
            ->first();

        if (!$subscription) {
            return response()->json(['error' => 'No active subscription was found.'], 404);
        }

        return response()->json([
            'error' => 'Self-service cancellation is not available for this subscription yet. Please contact support; your subscription has not been changed.',
        ], 409);
}

    public function renew(Request $request, $subscriptionId, PaystackClient $paystack)
    {
        $user = auth()->user();
        $subscription = Subscription::query()
            ->where('subscriptionId', $subscriptionId)
            ->where('userId', $user->id)
            ->first();

        if (!$subscription) {
            return response()->json(['error' => 'Subscription not found.'], 404);
        }

        $now = Carbon::now();
        $periodHasEnded =
            ($subscription->endDate && $subscription->endDate <= $now) ||
            ($subscription->nextBillingDate && $subscription->nextBillingDate <= $now);

        if ($subscription->status === 'active' && !$periodHasEnded) {
            return response()->json(['error' => 'This subscription is active and does not need renewal yet.'], 409);
        }

        if (
            $subscription->status === 'cancelled' &&
            $subscription->endDate &&
            $subscription->endDate > $now
        ) {
            return response()->json(['error' => 'This subscription remains active until its current period ends.'], 409);
        }

        if ($subscription->status === 'past_due') {
            if (!$subscription->endDate || $subscription->endDate <= $now) {
                return response()->json(['error' => 'The payment grace period has ended. Choose a plan to start a new subscription.'], 409);
            }

            if ($subscription->provider !== 'paystack' || !$subscription->providerSubscriptionId) {
                return response()->json(['error' => 'We could not open payment recovery for this subscription. Please contact support.'], 409);
            }

            if (!$paystack->isConfiguredForCurrentEnvironment()) {
                return response()->json(['error' => 'Payment gateway credentials do not match this environment.'], 503);
            }

            $managementResponse = $paystack->getSubscriptionManagementLink($subscription->providerSubscriptionId);
            $managementUrl = $managementResponse->json('data.link');

            if (!$managementResponse->successful() || !$managementUrl) {
                return response()->json(['error' => 'We could not open secure payment recovery. Please try again or contact support.'], 502);
            }

            return response()->json(['management_url' => $managementUrl]);
        }

        if (!in_array($subscription->status, ['expired', 'cancelled', 'failed'], true) && !$periodHasEnded) {
            return response()->json(['error' => 'This subscription cannot be renewed in its current state.'], 409);
        }

        return $this->create($request, $subscription->planId, $paystack);
    }

public function activate(Request $request, $subscriptionId)
    {
            if (!$this->isBillingAdmin()) {
                return response()->json(['message' => 'Billing administrator access is required.'], 403);
            }

        $subscription = Subscription::where('subscriptionId', $subscriptionId)->first();

        // Optional validation
        $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        // Check if already active
        if ($subscription->status === 'active') {
            return response()->json(['message' => 'Subscription is already active'], 400);
        }

        // Activate
        $now = Carbon::now();
        $subscription->status = 'active';
        $subscription->startDate = $now;
        $subscription->nextBillingDate = $now->addMonth(); // Assuming monthly billing; adjust if different interval
        $subscription->endDate = null; // Clear end date if any
        $subscription->metadata = array_merge($subscription->metadata ?? [], [
            'manual_activation' => true,
            'activation_reason' => $request->reason,
            'activated_at' => $now->toDateTimeString(),
        ]);
        $subscription->save();

        // Optional: Update user's current_plan
        $subscription->user->update(['currentPlan' => $subscription->planId]);

        Log::info("Subscription {$subscription->subscriptionId} manually activated", ['user_id' => $subscription->userId]);
        return response()->json([
            'message' => 'Subscription activated successfully',
            'subscription' => $subscription->fresh(['user', 'plan.currency_detail']),
        ]);
    }

    // PATCH /subscriptions/{id}/deactivate
    public function deactivate(Request $request, $subscriptionId)
    {
        if (!$this->isBillingAdmin()) {
            return response()->json(['message' => 'Billing administrator access is required.'], 403);
        }

        $subscription = Subscription::where('subscriptionId', $subscriptionId)->first();

        // Optional validation
        $request->validate([
            'reason' => 'nullable|string|max:255',
            'immediate' => 'boolean',
        ]);

        // Check if not active
        if ($subscription->status !== 'active') {
            return response()->json(['message' => 'Subscription is not active'], 400);
        }

        $now = Carbon::now();

        // Deactivate
        $subscription->status = 'cancelled'; // Or 'expired' based on your schema
        $subscription->endDate = $request->immediate ? $now : $subscription->nextBillingDate;
        $subscription->metadata = array_merge($subscription->metadata ?? [], [
            'manual_deactivation' => true,
            'deactivation_reason' => $request->reason,
            'deactivated_at' => $now->toDateTimeString(),
        ]);
        $subscription->save();

        // Optional: Downgrade user's plan to free/default
        $subscription->user->update(['currentPlan' => 1]); // Assuming 1 is free plan

        Log::info("Subscription {$subscription->subscriptionId} manually deactivated", ['user_id' => $subscription->userId]);

        return response()->json([
            'message' => 'Subscription deactivated successfully',
            'subscription' => $subscription->fresh(['user', 'plan.currency_detail']),
        ]);
    }

    // PATCH /subscriptions/{id}/expire
    public function expire(Request $request, $subscriptionId)
    {
        if (!$this->isBillingAdmin()) {
            return response()->json(['message' => 'Billing administrator access is required.'], 403);
        }

        $subscription = Subscription::where('subscriptionId', $subscriptionId)->first();

        $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        if (!$subscription) {
            return response()->json(['error' => 'Subscription not found'], 404);
        }

        if ($subscription->status !== 'active') {
            return response()->json(['message' => 'Subscription is not active'], 400);
        }

        $now = Carbon::now();

        // Mark as expired locally.
        $subscription->status = 'expired';
        $subscription->endDate = $now;
        $subscription->metadata = array_merge($subscription->metadata ?? [], [
            'manual_expire' => true,
            'expire_reason' => $request->reason,
            'expired_at' => $now->toDateTimeString(),
        ]);
        $subscription->save();

        // Downgrade user plan to default/free
        try {
            $subscription->user->update(['currentPlan' => 1]);
        } catch (\Exception $e) {
            Log::warning('Failed to downgrade user after expire', ['subscription' => $subscriptionId, 'error' => $e->getMessage()]);
        }

        Log::info("Subscription {$subscription->subscriptionId} manually expired", ['user_id' => $subscription->userId]);

        return response()->json([
            'message' => 'Subscription expired successfully',
            'subscription' => $subscription->fresh(['user', 'plan.currency_detail']),
        ]);
    }

    public function assignManual(Request $request)
    {
        if (!$this->isBillingAdmin()) {
            return response()->json(['message' => 'Billing administrator access is required.'], 403);
        }

        if ($request->boolean('complimentary') && !$this->isSuperAdmin()) {
            return response()->json(['message' => 'Only a super administrator can award complimentary subscriptions.'], 403);
        }

        $validated = $request->validate([
            'userId' => 'required|integer|exists:users,id',
            'planId' => 'required|integer|exists:plans,planId',
            'status' => 'nullable|in:active,pending',
            'complimentary' => 'nullable|boolean',
            'startDate' => 'nullable|date',
            'nextBillingDate' => 'nullable|date',
            'expiryDate' => 'nullable|date',
            'durationMonths' => 'nullable|integer|min:1|max:24',
            'amountPaid' => 'nullable|numeric|min:0',
            'paymentChannel' => 'nullable|string|max:100',
            'paymentReference' => 'nullable|string|max:255',
            'evidenceUrl' => 'nullable|url|max:500',
            'reason' => $request->boolean('complimentary') ? 'required|string|max:1000' : 'nullable|string|max:1000',
        ]);

        $isComplimentary = (bool) ($validated['complimentary'] ?? false);
        if ($isComplimentary && ($validated['status'] ?? 'active') !== 'active') {
            return response()->json(['message' => 'Complimentary subscriptions must be activated immediately.'], 422);
        }

        $status = $isComplimentary ? 'active' : ($validated['status'] ?? 'active');

        $user = User::findOrFail($validated['userId']);
        $plan = Plans::with('currency_detail')->where('planId', $validated['planId'])->firstOrFail();

        $start = isset($validated['startDate']) ? Carbon::parse($validated['startDate']) : Carbon::now();
        $nextBilling = isset($validated['nextBillingDate'])
            ? Carbon::parse($validated['nextBillingDate'])
            : ($isComplimentary && isset($validated['expiryDate'])
                ? Carbon::parse($validated['expiryDate'])
                : (clone $start)->addMonthsNoOverflow($isComplimentary ? (int) ($validated['durationMonths'] ?? 1) : 1));
        $expiry = isset($validated['expiryDate'])
            ? Carbon::parse($validated['expiryDate'])
            : $nextBilling;

        $adminId = auth()->id();

        $metadata = [
            'manual_activation' => true,
            'assigned_by_admin_id' => $adminId,
            'assigned_at' => Carbon::now()->toDateTimeString(),
            'payment_channel' => $isComplimentary ? 'complimentary_award' : ($validated['paymentChannel'] ?? null),
            'payment_reference' => $validated['paymentReference'] ?? null,
            'amount_paid' => $isComplimentary ? 0 : ($validated['amountPaid'] ?? null),
            'evidence_url' => $validated['evidenceUrl'] ?? null,
            'reason' => $validated['reason'] ?? null,
            'complimentary_award' => $isComplimentary,
        ];

        $subscription = DB::transaction(function () use ($user, $plan, $status, $start, $nextBilling, $expiry, $metadata) {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $hasCurrentSubscription = Subscription::query()
                ->where('userId', $user->id)
                ->where('status', 'active')
                ->where(function ($query) {
                    $query->whereNull('startDate')->orWhere('startDate', '<=', now());
                })
                ->where(function ($query) {
                    $query->whereNull('endDate')->orWhere('endDate', '>', now());
                })
                ->where(function ($query) {
                    $query->whereNull('nextBillingDate')->orWhere('nextBillingDate', '>', now());
                })
                ->lockForUpdate()
                ->exists();

            if ($hasCurrentSubscription) {
                return null;
            }

            $newSub = Subscription::create([
                'userId' => $user->id,
                'planId' => $plan->planId,
                'status' => $status,
                'startDate' => $status === 'active' ? $start : null,
                'nextBillingDate' => $status === 'active' ? $nextBilling : null,
                'endDate' => $status === 'active' ? $expiry : null,
                'metadata' => array_filter($metadata, fn ($value) => $value !== null && $value !== ''),
            ]);

            $user->update(['currentPlan' => $plan->planId]);

            return $newSub;
        });

        if (!$subscription) {
            return response()->json([
                'message' => 'This user already has an active subscription. Their existing plan was left unchanged.',
            ], 409);
        }

        $emailStatus = null;
        if ($isComplimentary) {
            if (!$user->email) {
                $emailStatus = 'no_email';
            } else {
                try {
                    $expiryLabel = $subscription->endDate?->format('F j, Y') ?? 'until further notice';
                    $messageBody = "Your {$plan->planName} subscription has been awarded to you at no cost.\n\n"
                        . "Your access is active now and is scheduled through {$expiryLabel}. No payment was collected for this award.\n\n"
                        . 'We look forward to helping your business grow with ClickInvoice.';

                    Mail::to($user->email)->send(new UserNotificationMail(
                            user: $user,
                            subjectLine: 'You have been awarded a ClickInvoice subscription',
                            messageBody: $messageBody
                        ));
                    $emailStatus = 'sent';
                } catch (\Throwable $exception) {
                    $emailStatus = 'failed';
                    Log::error('Complimentary subscription award email failed', [
                        'subscription_id' => $subscription->subscriptionId,
                        'user_id' => $user->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        return response()->json([
            'message' => $isComplimentary
                ? match ($emailStatus) {
                    'sent' => 'Complimentary subscription awarded and email sent successfully.',
                    'no_email' => 'Complimentary subscription awarded, but the user account has no email address.',
                    default => 'Complimentary subscription awarded, but the email could not be sent. Please retry or contact support.',
                }
                : 'Manual subscription assigned successfully',
            'emailStatus' => $emailStatus,
            'subscription' => $subscription->fresh(['user', 'plan.currency_detail']),
        ], 201);
    }

    public function extendPeriod(Request $request, $subscriptionId, SubscriptionEmailOutboxDelivery $emailDelivery)
    {
        if (!$this->isBillingAdmin()) {
            return response()->json(['message' => 'Billing administrator access is required.'], 403);
        }

        $validated = $request->validate([
            'months' => 'required|integer|min:1|max:24',
            'reason' => 'required|string|max:1000',
        ]);

        $result = DB::transaction(function () use ($subscriptionId, $validated) {
            $subscription = Subscription::query()
                ->with(['user', 'plan'])
                ->lockForUpdate()
                ->find($subscriptionId);

            if (!$subscription) {
                return ['error' => 'Subscription not found.', 'status' => 404];
            }

            if ($subscription->status !== 'active' || !$subscription->hasAccessAt()) {
                return ['error' => 'Only a currently active subscription can be extended.', 'status' => 409];
            }

            if (!filter_var($subscription->user?->email, FILTER_VALIDATE_EMAIL)) {
                return ['error' => 'The user needs a valid email address before access can be extended.', 'status' => 422];
            }

            $currentAccessThrough = $subscription->accessThroughDate();
            if (!$currentAccessThrough || !$currentAccessThrough->isFuture()) {
                return ['error' => 'This subscription has no future access period to extend.', 'status' => 409];
            }

            $now = Carbon::now();
            $extendedThrough = $currentAccessThrough->copy()->addMonthsNoOverflow((int) $validated['months']);
            $metadata = is_array($subscription->metadata) ? $subscription->metadata : [];
            $extensions = is_array($metadata['admin_access_extensions'] ?? null)
                ? $metadata['admin_access_extensions']
                : [];
            $extensions[] = [
                'months' => (int) $validated['months'],
                'previous_access_through' => $currentAccessThrough->toDateTimeString(),
                'access_through' => $extendedThrough->toDateTimeString(),
                'reason' => $validated['reason'],
                'granted_by_admin_id' => auth()->id(),
                'granted_at' => $now->toDateTimeString(),
            ];
            $metadata['admin_access_extension_until'] = $extendedThrough->toDateTimeString();
            $metadata['admin_access_extensions'] = $extensions;

            $subscription->update(['metadata' => $metadata]);

            $messageBody = "Your {$subscription->plan?->planName} subscription access has been extended by {$validated['months']} month(s).\n\n"
                . "Your ClickInvoice access is now available through {$extendedThrough->format('F j, Y')}.\n\n"
                . 'This access extension does not change your Paystack billing schedule.';

            $outboxMessage = SubscriptionEmailOutbox::create([
                'subscription_id' => $subscription->subscriptionId,
                'user_id' => $subscription->userId,
                'recipient_email' => $subscription->user->email,
                'subject' => 'Your ClickInvoice subscription access has been extended',
                'message_body' => $messageBody,
                'action_text' => 'View billing details',
                'action_url' => rtrim((string) config('clickinvoice.app_url'), '/') . '/dashboard/my-subscriptions/',
                'status' => 'pending',
                'attempts' => 0,
                'next_attempt_at' => now(),
            ]);

            return [
                'subscription' => $subscription->fresh(['user', 'plan.currency_detail']),
                'previousAccessThrough' => $currentAccessThrough,
                'extendedThrough' => $extendedThrough,
                'months' => (int) $validated['months'],
                'outboxId' => $outboxMessage->id,
            ];
        });

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], $result['status']);
        }

        $subscription = $result['subscription'];
        $emailStatus = $emailDelivery->deliver((int) $result['outboxId']);
        if ($emailStatus === 'not_pending') {
            $emailStatus = 'pending';
        }

        return response()->json([
            'message' => match ($emailStatus) {
                'sent' => 'Subscription access extended and confirmation email sent.',
                    'failed' => 'Subscription access was extended. Email delivery failed temporarily and will be retried automatically.',
                default => 'Subscription access was extended. Confirmation email is pending delivery and will be retried automatically.',
            },
            'emailStatus' => $emailStatus,
            'subscription' => $subscription,
            'previousAccessThrough' => $result['previousAccessThrough']->toIso8601String(),
            'accessThrough' => $result['extendedThrough']->toIso8601String(),
        ], $emailStatus === 'sent' ? 200 : 202);
    }

    public function retryExtensionEmail(Request $request, $subscriptionId, SubscriptionEmailOutboxDelivery $emailDelivery)
    {
        if (!$this->isBillingAdmin()) {
            return response()->json(['message' => 'Billing administrator access is required.'], 403);
        }

        $message = SubscriptionEmailOutbox::query()
            ->where('subscription_id', $subscriptionId)
            ->where('status', 'failed')
            ->latest('id')
            ->first();

        if (!$message) {
            return response()->json(['message' => 'No failed access-extension email is available to retry.'], 404);
        }

        $message->update([
            'status' => 'pending',
            'attempts' => 0,
            'next_attempt_at' => now(),
            'last_error' => null,
        ]);

        $emailStatus = $emailDelivery->deliver((int) $message->id);

        return response()->json([
            'message' => $emailStatus === 'sent'
                ? 'Extension confirmation email sent successfully.'
                : 'Extension confirmation email is pending automatic retry.',
            'emailStatus' => $emailStatus === 'not_pending' ? 'pending' : $emailStatus,
        ], $emailStatus === 'sent' ? 200 : 202);
    }

    public function bulkAction(Request $request)
    {
        if (!$this->isBillingAdmin()) {
            return response()->json(['message' => 'Billing administrator access is required.'], 403);
        }

        $validated = $request->validate([
            'subscriptionIds' => 'required|array|min:1',
            'subscriptionIds.*' => 'integer|exists:subscriptions,subscriptionId',
            'action' => 'required|in:activate,deactivate,expire',
            'reason' => 'nullable|string|max:1000',
        ]);

        $subscriptions = Subscription::whereIn('subscriptionId', $validated['subscriptionIds'])
            ->with('user')
            ->get();

        $now = Carbon::now();
        $updated = 0;

        foreach ($subscriptions as $subscription) {
            if ($validated['action'] === 'activate') {
                $subscription->status = 'active';
                $subscription->startDate = $subscription->startDate ?: $now;
                $subscription->nextBillingDate = $subscription->nextBillingDate ?: (clone $now)->addMonth();
                $subscription->endDate = null;
            } elseif ($validated['action'] === 'expire') {
                $subscription->status = 'expired';
                $subscription->endDate = $now;
            } else {
                $subscription->status = 'cancelled';
                $subscription->endDate = $now;
            }

            $existingMeta = is_array($subscription->metadata) ? $subscription->metadata : [];
            $subscription->metadata = array_merge($existingMeta, [
                'bulk_action' => $validated['action'],
                'bulk_action_reason' => $validated['reason'] ?? null,
                'bulk_action_by_admin_id' => auth()->id(),
                'bulk_action_at' => $now->toDateTimeString(),
            ]);
            $subscription->save();

            if ($subscription->user && $validated['action'] === 'activate') {
                $subscription->user->update(['currentPlan' => $subscription->planId]);
            }
            if ($subscription->user && in_array($validated['action'], ['expire', 'deactivate'])) {
                $subscription->user->update(['currentPlan' => 1]);
            }

            $updated++;
        }

        return response()->json([
            'message' => 'Bulk action completed successfully',
            'updated' => $updated,
        ]);
    }

    private function isBillingAdmin(): bool
    {
        $role = strtoupper(trim((string) auth()->user()?->user_role?->roleName));

        return in_array($role, ['ADMIN', 'SUPER_ADMIN', 'SUPERADMIN'], true);
    }

    private function isSuperAdmin(): bool
    {
        $role = strtoupper(trim((string) auth()->user()?->user_role?->roleName));

        return in_array($role, ['SUPER_ADMIN', 'SUPERADMIN'], true);
    }
}