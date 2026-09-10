<?php

namespace App\Models;

use App\Models\Traits\HasPublicUid;
use Illuminate\Database\Eloquent\Model;

class SupportSession extends Model
{
    use HasPublicUid;

    protected $fillable = [
        'uid',
        'support_user_id',
        'tenant_id',
        'impersonated_user_id',
        'token_id',
        'reason',
        'started_at',
        'expires_at',
        'ended_at',
        'ip_address',
        'user_agent',
    ];

    protected $hidden = [
        'id',
        'support_user_id',
        'tenant_id',
        'impersonated_user_id',
        'token_id',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function supportUser()
    {
        return $this->belongsTo(User::class, 'support_user_id')->withoutGlobalScopes();
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function impersonatedUser()
    {
        return $this->belongsTo(User::class, 'impersonated_user_id')->withoutGlobalScopes();
    }

    public function isActive(): bool
    {
        return $this->ended_at === null && now()->lt($this->expires_at);
    }
}
