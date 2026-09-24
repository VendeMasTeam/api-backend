<?php

namespace App\Models\Traits;

use App\Models\AdminRole;
use App\Models\Permission;

trait HasAdminAccessControl
{
    public function adminRoles()
    {
        return $this->belongsToMany(AdminRole::class, 'admin_role_user');
    }

    public function hasAdminPermissionTo(string $permissionKey): bool
    {
        if ($this->permissions()
            ->where('scope', Permission::SCOPE_PLATFORM)
            ->where('key', $permissionKey)
            ->exists()) {
            return true;
        }

        return $this->adminRoles()
            ->whereHas('permissions', fn ($q) => $q
                ->where('scope', Permission::SCOPE_PLATFORM)
                ->where('key', $permissionKey))
            ->exists();
    }

    public function assignAdminRole(AdminRole $role): void
    {
        $this->adminRoles()->syncWithoutDetaching([$role->getKey()]);
    }

    public function removeAdminRole(AdminRole $role): void
    {
        $this->adminRoles()->detach($role->getKey());
    }

    public function hasAdminRole(string $roleKey): bool
    {
        return $this->adminRoles()->where('key', $roleKey)->exists();
    }

    public function effectiveAdminPermissions()
    {
        $directPermissions = $this->permissions()
            ->where('scope', Permission::SCOPE_PLATFORM)
            ->get();
        $rolePermissions = Permission::query()
            ->where('scope', Permission::SCOPE_PLATFORM)
            ->whereHas('adminRoles.users', fn ($q) => $q->where('users.id', $this->getKey()))
            ->get();

        return $directPermissions
            ->merge($rolePermissions)
            ->unique('id')
            ->values();
    }
}
