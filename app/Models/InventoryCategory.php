<?php

namespace App\Models;

use App\Models\Traits\HasPublicUid;
use App\Models\Traits\HasTenantRelation;
use App\Models\Traits\TenantScope;
use Illuminate\Database\Eloquent\Model;

class InventoryCategory extends Model
{
    use HasPublicUid, HasTenantRelation, TenantScope;

    protected $fillable = [
        'uid',
        'tenant_id',
        'name',
        'key',
        'description',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'key',
    ];

    public function products()
    {
        return $this->hasMany(InventoryProduct::class, 'category_id');
    }
}
