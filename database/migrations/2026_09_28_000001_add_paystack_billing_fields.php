<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('paystackPlanCode')->nullable()->after('flutterwavePlanId');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('status', 32)->default('pending')->change();
            $table->string('provider')->default('flutterwave')->after('flutterwaveSubscriptionId');
            $table->string('providerSubscriptionId')->nullable()->after('provider');
            $table->string('providerCustomerCode')->nullable()->after('providerSubscriptionId');
            $table->index(['provider', 'providerSubscriptionId']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('flutterwaveTxId')->nullable()->change();
            $table->string('provider')->default('flutterwave');
            $table->string('providerReference')->nullable();
            $table->string('providerTransactionId')->nullable();
            $table->unsignedBigInteger('userId')->nullable();
            $table->unique(['provider', 'providerReference'], 'payments_provider_reference_unique');
            $table->foreign('userId')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        throw new \LogicException(
            'This billing migration is forward-only. Do not roll it back because doing so would delete provider references and plan codes.'
        );
    }
};