<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable()->after('updated_at');
            $table->text('void_reason')->nullable()->after('voided_at');
        });

        Schema::create('invoice_audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_id')->index();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->string('action', 64)->index();
            $table->text('reason')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->foreign('invoice_id')->references('invoiceId')->on('invoices')->onDelete('cascade');
            $table->foreign('tenant_id')->references('tenantId')->on('tenants')->onDelete('cascade');
            $table->foreign('actor_user_id')->references('id')->on('users')->onDelete('set null');
        });

        Schema::table('tenant_staff', function (Blueprint $table) {
            $table->string('role', 32)->default('member')->after('userId');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_staff', function (Blueprint $table) {
            $table->dropColumn('role');
        });

        Schema::dropIfExists('invoice_audit_events');

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['voided_at', 'void_reason']);
        });
    }
};
