<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('invoices', 'status')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->string('status')->nullable()->default('UNPAID')->after('tenantId');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('invoices', 'status')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }
    }
};
