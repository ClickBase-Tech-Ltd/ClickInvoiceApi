<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('paystackTestPlanCode')->nullable()->after('paystackPlanCode');
        });
    }

    public function down(): void
    {
        throw new \LogicException(
            'This billing migration is forward-only. Do not roll it back because it may contain sandbox plan references.'
        );
    }
};