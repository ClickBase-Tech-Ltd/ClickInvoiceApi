<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory;

    // Set the primary key since it's not the default 'id'
    protected $primaryKey = 'invoiceNumber';

    // Mass assignable fields
    protected $fillable = [
        'invoiceId',
        'userGeneratedInvoiceId',
        'projectName',
        'invoiceDate',
        'dueDate',
        'invoicePassword',
        'notes',
        'currency',
        'amountPaid',
        'balanceDue',
        'accountName',
        'accountNumber',
        'bank',
        'taxPercentage',
        'tenantId',
        'status',
        'createdBy',
        'customerId',
        'receiptId',
        'discountPercentage'
    ];

    // Relationships
    public function items()
    {
        return $this->hasMany(InvoiceItem::class, 'invoiceNumber', 'invoiceNumber');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenantId', 'tenantId');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'createdBy', 'id');
    }

    public function currencyDetail()
    {
        return $this->belongsTo(Currency::class, 'currency', 'currencyId');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customerId', 'customerId');
    }
}
