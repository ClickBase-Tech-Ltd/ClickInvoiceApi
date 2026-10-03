<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionEmailOutbox extends Model
{
    protected $table = 'subscription_email_outbox';

    protected $fillable = [
        'subscription_id',
        'user_id',
        'recipient_email',
        'subject',
        'message_body',
        'action_text',
        'action_url',
        'status',
        'attempts',
        'next_attempt_at',
        'sent_at',
        'last_error',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'next_attempt_at' => 'datetime',
        'sent_at' => 'datetime',
    ];
}