<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('provider')->nullable()->change();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('provider')->nullable()->change();
            $table->string('flutterwaveTxRef')->nullable()->change();
        });
    }

    public function down(): void
    {
        throw new \LogicException(
            'This migration is forward-only so new provider records remain independent of legacy payment fields.'
        );
    }
};