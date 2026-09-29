<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plans extends Model
{
    use HasFactory;
    public $table = 'plans';
    protected $primaryKey = 'planId';
    protected $fillable = ['planName', 'price', 'currency', 'features', 'isPopular', 'tenantLimit', 'invoiceLimit', 'paystackPlanCode', 'paystackTestPlanCode'];

    protected $hidden = ['flutterwavePlanId'];

    public function paystackPlanCodeForCurrentEnvironment(): ?string
    {
        return app()->environment('production')
            ? $this->paystackPlanCode
            : $this->paystackTestPlanCode;
    }

    public function currency_detail()
    {
        return $this->belongsTo(Currency::class, 'currency', 'currencyId');
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class, 'planId');
    }
}
