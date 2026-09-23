<?php

namespace App\Models;

use App\Models\Traits\HasPublicUid;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    use HasPublicUid;

    public const SCOPE_PLATFORM = 'platform';

    public const SCOPE_TENANT = 'tenant';

    protected $fillable = [
        'uid',
        'key',
        'module',
        'action',
        'description',
        'scope',
    ];

    protected $hidden = [
        'id',
    ];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'permission_role')
            ->withTimestamps();
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'permission_user')
            ->withTimestamps();
    }

    public function adminRoles()
    {
        return $this->belongsToMany(AdminRole::class, 'admin_role_permission');
    }
}
