<?php

namespace App\Models\Traits;

use App\Models\Permission;
use App\Models\Role;

trait HasAccessControl
{
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_user')
            ->withTimestamps();
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'permission_user')
            ->withTimestamps();
    }

    public function hasRole(string $roleKey): bool
    {
        return $this->roles()->where('key', $roleKey)->exists();
    }

    public function hasDirectPermission(string $permissionKey): bool
    {
        return $this->permissions()->where('key', $permissionKey)->exists();
    }

    public function hasPermissionTo(string $permissionKey): bool
    {
        if ($this->permissions()
            ->where('scope', Permission::SCOPE_TENANT)
            ->where('key', $permissionKey)
            ->exists()) {
            return true;
        }

        return $this->roles()
            ->whereHas('permissions', fn ($query) => $query
                ->where('scope', Permission::SCOPE_TENANT)
                ->where('key', $permissionKey))
            ->exists();
    }

    public function effectivePermissions()
    {
        $directPermissions = $this->permissions()
            ->where('scope', Permission::SCOPE_TENANT)
            ->get();
        $rolePermissions = Permission::query()
            ->where('scope', Permission::SCOPE_TENANT)
            ->whereHas('roles.users', fn ($query) => $query->where('users.id', $this->getKey()))
            ->get();

        return $directPermissions
            ->merge($rolePermissions)
            ->unique('id')
            ->values();
    }

    public function assignRole(Role $role): void
    {
        $this->roles()->syncWithoutDetaching([$role->getKey()]);
    }

    public function removeRole(Role $role): void
    {
        $this->roles()->detach($role->getKey());
    }

    public function givePermissionTo(Permission $permission): void
    {
        $this->permissions()->syncWithoutDetaching([$permission->getKey()]);
    }

    public function revokePermissionTo(Permission $permission): void
    {
        $this->permissions()->detach($permission->getKey());
    }
}
