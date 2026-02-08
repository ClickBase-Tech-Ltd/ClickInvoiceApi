<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $table = 'tenants';

    protected $primaryKey = 'tenantId';

    protected $fillable = [
        'tenantId',
        'tenantName',
        'tenantPhone',
        'tenantEmail',
        'tenantLogo',
        'authorizedSignature',
        'countryCode',
        'currency',
        'timezone',
        'gatewayPreference',
        'ownerId',
        'isDefault',
        'tenantAddress',
        'status',
        'taxId'
    ];

    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency', 'currencyId');
    }

    public function payment_gateway()
    {
        return $this->belongsTo(PaymentGateway::class, 'gatewayPreference', 'gatewayId');
    }



public function owner()
{
    return $this->belongsTo(User::class, 'id', 'ownerId');
}
}

