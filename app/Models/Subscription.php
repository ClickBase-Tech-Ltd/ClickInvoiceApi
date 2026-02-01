<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasFactory;

    // Table name (optional, Laravel would infer correctly)
    protected $table = 'subscriptions';

    // Custom primary key
    protected $primaryKey = 'subscriptionId';

    // If your PK is auto-incrementing (it is)
    public $incrementing = true;

    // PK type
    protected $keyType = 'int';

    protected $fillable = [
        'userId',
        'planId',
        'flutterwaveSubscriptionId',
        'status',
        'startDate',
        'nextBillingDate',
        'endDate',
        'metadata',
    ];

    protected $casts = [
        'startDate'        => 'datetime',
        'nextBillingDate'  => 'datetime',
        'endDate'          => 'datetime',
        'metadata'         => 'array',
        'flutterwaveCancelledAt' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function user()
    {
        return $this->belongsTo(User::class, 'userId');
    }

      public function plan()
    {
        return $this->belongsTo(Plans::class, 'planId');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'subscriptionId', 'subscriptionId');
    }

    
}
