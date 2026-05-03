<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantStaff extends Model
{
    protected $table = 'tenant_staff';

    protected $primaryKey = 'tenantStaffId';

    protected $fillable = [
        'tenantStaffId',
        'tenantId',
        'userId',
        'role',
    ];
}
