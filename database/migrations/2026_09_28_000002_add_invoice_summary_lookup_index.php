<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->index(
                ['tenantId', 'createdBy', 'created_at'],
                'invoices_tenant_creator_created_at_index'
            );
        });
    }

    public function down(): void
    {
        throw new \LogicException(
            'This performance migration is forward-only. Remove the index in a separately reviewed migration if needed.'
        );
    }
};