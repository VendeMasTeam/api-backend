<?php

namespace App\Models;

use App\Models\Traits\AppliesRowLevelSecurity;
use App\Models\Traits\HasCustomFieldValues;
use App\Models\Traits\HasPublicUid;
use App\Models\Traits\HasTags;
use App\Models\Traits\HasTenantRelation;
use App\Models\Traits\TenantScope;
use Illuminate\Database\Eloquent\Model;

class Opportunity extends Model
{
    use AppliesRowLevelSecurity, HasCustomFieldValues, HasPublicUid, HasTags, HasTenantRelation, TenantScope;

    protected $fillable = [
        'uid',
        'tenant_id',
        'owner_user_id',
        'stage_id',
        'opportunityable_type',
        'opportunityable_id',
        'title',
        'email',
        'lead_origin',
        'amount',
        'currency',
        'expected_close_date',
        'description',
        'won_at',
        'lost_at',
        'kanban_position',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'owner_user_id',
        'stage_id',
        'opportunityable_id',
    ];

    protected $appends = [
        'email',
        'owner_user_uid',
        'stage_uid',
        'stage_name',
        'opportunityable_uid',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expected_close_date' => 'date',
        'won_at' => 'datetime',
        'lost_at' => 'datetime',
        'kanban_position' => 'integer',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function stage()
    {
        return $this->belongsTo(OpportunityStage::class, 'stage_id');
    }

    public function opportunityable()
    {
        return $this->morphTo();
    }

    public function project()
    {
        return $this->hasOne(Project::class);
    }

    public function activities()
    {
        return $this->morphMany(Activity::class, 'activityable');
    }

    public function quotations()
    {
        return $this->morphMany(Quotation::class, 'quoteable');
    }

    public function lostReasons()
    {
        return $this->hasMany(LostReason::class);
    }

    public function getOwnerUserUidAttribute()
    {
        return $this->owner?->uid
            ?? ($this->owner_user_id ? User::query()->whereKey($this->owner_user_id)->value('uid') : null);
    }

    public function getStageUidAttribute()
    {
        return $this->stage?->uid
            ?? ($this->stage_id ? OpportunityStage::query()->whereKey($this->stage_id)->value('uid') : null);
    }

    public function getStageNameAttribute()
    {
        return $this->stage?->name
            ?? ($this->stage_id ? OpportunityStage::query()->whereKey($this->stage_id)->value('name') : null);
    }

    public function getOpportunityableUidAttribute()
    {
        return $this->opportunityable?->uid;
    }

    public function getEmailAttribute()
    {
        return $this->attributes['email'] ?? null;
    }

    public function resolveDefaultOwnerUserId(): ?int
    {
        return auth()->id();
    }
}
