<?php

namespace App\Models;

use App\Models\Traits\HasPublicUid;
use App\Models\Traits\TenantScope;
use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    use HasPublicUid, TenantScope;

    protected $fillable = [
        'uid',
        'tenant_id',
        'name',
        'key',
        'color',
        'category',
        'entity_types',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected $casts = [
        'entity_types' => 'array',
    ];

    public function getEntityTypesAttribute($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $decoded = is_array($value) ? $value : json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function accounts()
    {
        return $this->morphedByMany(Account::class, 'taggable')->withTimestamps();
    }

    public function contacts()
    {
        return $this->morphedByMany(Contact::class, 'taggable')->withTimestamps();
    }

    public function crmEntities()
    {
        return $this->morphedByMany(CrmEntity::class, 'taggable')->withTimestamps();
    }

    public function opportunities()
    {
        return $this->morphedByMany(Opportunity::class, 'taggable')->withTimestamps();
    }
}
