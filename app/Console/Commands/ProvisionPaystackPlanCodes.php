<?php

namespace App\Console\Commands;

use App\Models\Plans;
use App\Services\PaystackClient;
use Illuminate\Console\Command;

class ProvisionPaystackPlanCodes extends Command
{
    protected $signature = 'paystack:provision-plan-codes {planIds* : Paid plan IDs to provision}';

    protected $description = 'Create missing Paystack monthly plan codes using a test-mode secret key only';

    public function handle(PaystackClient $paystack): int
    {
        $secretKey = (string) config('services.paystack.secret_key');
        if (!str_starts_with($secretKey, 'sk_test_')) {
            $this->error('Refusing to provision plans: the configured Paystack key is not a test key.');
            return self::FAILURE;
        }

        $plans = Plans::with('currency_detail')
            ->whereIn('planId', $this->argument('planIds'))
            ->orderBy('planId')
            ->get();

        if ($plans->isEmpty()) {
            $this->error('No matching plans were found.');
            return self::FAILURE;
        }

        foreach ($plans as $plan) {
            if ((float) $plan->price <= 0) {
                $this->warn("Skipping free plan {$plan->planId} ({$plan->planName}).");
                continue;
            }

            if ($plan->paystackTestPlanCode) {
                $this->line("Plan {$plan->planId} already has a Paystack test plan code; unchanged.");
                continue;
            }

            if (!$plan->currency_detail?->currencyCode) {
                $this->error("Plan {$plan->planId} has no currency code; skipped.");
                continue;
            }

            $response = $paystack->createPlan(
                (string) $plan->planName,
                (int) round(((float) $plan->price) * 100),
                (string) $plan->currency_detail->currencyCode,
                'monthly'
            );
            $planCode = $response->json('data.plan_code');

            if (!$response->successful() || !$planCode) {
                $this->error("Paystack could not provision plan {$plan->planId}; local plan was not modified.");
                continue;
            }

            $plan->paystackTestPlanCode = $planCode;
            $plan->save();
            $this->info("Provisioned test plan code for plan {$plan->planId} ({$plan->planName}).");
        }

        return self::SUCCESS;
    }
}
