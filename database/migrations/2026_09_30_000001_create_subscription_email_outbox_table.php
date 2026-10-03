<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_email_outbox', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subscription_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('recipient_email');
            $table->string('subject');
            $table->longText('message_body');
            $table->string('action_text')->nullable();
            $table->text('action_url')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->foreign('subscription_id')
                ->references('subscriptionId')
                ->on('subscriptions')
                ->nullOnDelete();
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_email_outbox');
    }
};