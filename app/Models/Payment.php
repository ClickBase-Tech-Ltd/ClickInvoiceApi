<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $table = 'payments';

    protected $fillable = [
        'subscriptionId',
        'provider',
        'providerReference',
        'providerTransactionId',
        'userId',
        'amount',
        'currency',
        'status',
        'responseData',
    ];

    protected $casts = [
        'amount'        => 'decimal:2',
        'responseData'  => 'array',
    ];

    protected $hidden = [
        'flutterwaveTxRef',
        'flutterwaveTxId',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function subscription()
    {
        return $this->belongsTo(
            Subscription::class,
            'subscriptionId',
            'subscriptionId'
        );
    }
}
