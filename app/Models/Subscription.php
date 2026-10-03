<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

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
        'provider',
        'providerSubscriptionId',
        'providerCustomerCode',
        'providerSubscriptionEmailToken',
        'providerSubscriptionDisabledAt',
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
        'providerSubscriptionEmailToken' => 'encrypted',
        'providerSubscriptionDisabledAt' => 'datetime',
    ];

    protected $hidden = [
        'flutterwaveSubscriptionId',
        'flutterwaveCancelledAt',
        'providerSubscriptionEmailToken',
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

    public function accessExtensionUntil(): ?Carbon
    {
        $value = $this->metadata['admin_access_extension_until'] ?? null;

        return $value ? Carbon::parse($value) : null;
    }

    public function hasAccessAt(?Carbon $at = null): bool
    {
        $at ??= Carbon::now();

        if ($this->startDate && $this->startDate->gt($at)) {
            return false;
        }

        if ($this->status === 'past_due') {
            return (bool) (($this->endDate && $this->endDate->gt($at)) || $this->accessExtensionUntil()?->gt($at));
        }

        if ($this->status !== 'active') {
            return false;
        }

        $withinStoredPeriod =
            (!$this->endDate || $this->endDate->gt($at)) &&
            (!$this->nextBillingDate || $this->nextBillingDate->gt($at));

        return $withinStoredPeriod || (bool) ($this->accessExtensionUntil()?->gt($at));
    }

    public function accessThroughDate(): ?Carbon
    {
        $periodDates = array_values(array_filter([
            $this->endDate,
            $this->nextBillingDate,
        ]));

        if ($periodDates) {
            usort($periodDates, fn (Carbon $left, Carbon $right) => $left->getTimestamp() <=> $right->getTimestamp());
            $periodThrough = $periodDates[0]->copy();
        } else {
            $periodThrough = null;
        }

        $extensionThrough = $this->accessExtensionUntil();
        if (!$periodThrough) {
            return $extensionThrough;
        }
        if (!$extensionThrough) {
            return $periodThrough;
        }

        if ($periodThrough->gte($extensionThrough)) {
            return $periodThrough;
        }

        return $extensionThrough;
    }

    
}
