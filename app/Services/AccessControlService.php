<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\ApiIndex;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccessControlService
{
    public function __construct(private readonly PlanPermissionService $planPermissionService) {}

    public function getRoles(array $filters = [])
    {
        $validated = Validator::make($filters, [
            'only_active_modules' => 'nullable|string|in:true,false,1,0',
            'search' => 'nullable|string|max:255',
        ])->validate();

        $roles = ApiIndex::paginateOrGet(
            Role::query()
                ->with('permissions')
                ->withCount('users')
                ->withCount('users as total_usuarios')
                ->when(! empty($validated['search']), function ($query) use ($validated) {
                    $search = '%'.mb_strtolower($validated['search']).'%';

                    $query->whereRaw('LOWER(name) LIKE ?', [$search]);
                })
                ->orderBy('name'),
            $filters,
            'roles_page'
        );

        if (($validated['only_active_modules'] ?? null) && filter_var($validated['only_active_modules'], FILTER_VALIDATE_BOOLEAN)) {
            $tenant = auth()->user()?->tenant;

            if ($tenant) {
                $collection = $roles instanceof LengthAwarePaginator
                    ? $roles->getCollection()
                    : $roles;

                $collection->each(function (Role $role) use ($tenant) {
                    $role->setRelation(
                        'permissions',
                        $this->planPermissionService->filterPermissionsForTenant($role->permissions, $tenant)
                    );
                });
            }
        }

        return $roles;
    }

    public function getPermissions()
    {
        return Permission::query()
            ->where('scope', Permission::SCOPE_TENANT)
            ->orderBy('module')
            ->orderBy('action')
            ->get();
    }

    public function getUserAccess(string $userUid): array
    {
        $user = $this->findUser($userUid);
        $user->load(['roles.permissions', 'permissions']);

        return [
            'user' => $user,
            'roles' => $user->roles,
            'direct_permissions' => $user->permissions,
            'effective_permissions' => $user->effectivePermissions(),
        ];
    }

    public function assignRoleToUser(string $userUid, string $roleUid): array
    {
        $user = $this->findUser($userUid);
        $role = $this->findRole($roleUid);

        $user->assignRole($role);

        return $this->getUserAccess($userUid);
    }

    public function createRole(array $data): Role
    {
        $data['key'] = $this->uniqueRoleKey($data['key'] ?? $data['name']);
        $permissionIds = $this->permissionIdsFromUids($data['permission_uids'] ?? []);

        $role = Role::query()->create([
            'name' => $data['name'],
            'key' => $data['key'],
            'description' => $data['description'] ?? null,
            'is_system' => false,
        ]);

        $role->permissions()->sync($permissionIds);

        return $role->fresh('permissions');
    }

    public function updateRole(string $roleUid, array $data): Role
    {
        $role = $this->findRole($roleUid);

        if ($role->is_system) {
            throw ValidationException::withMessages([
                'role_uid' => ['Los roles del sistema no se pueden editar'],
            ]);
        }

        if (array_key_exists('key', $data) && $data['key'] === null) {
            unset($data['key']);
        }

        if (isset($data['key']) && $data['key'] !== $role->key) {
            $data['key'] = $this->uniqueRoleKey($data['key'], $role->getKey());
        }

        $permissionIds = array_key_exists('permission_uids', $data)
            ? $this->permissionIdsFromUids($data['permission_uids'])
            : null;

        $role->update([
            'name' => $data['name'] ?? $role->name,
            'key' => $data['key'] ?? $role->key,
            'description' => array_key_exists('description', $data) ? $data['description'] : $role->description,
        ]);

        if (array_key_exists('permission_uids', $data)) {
            $role->permissions()->sync($permissionIds);
        }

        return $role->fresh('permissions');
    }

    public function deleteRole(string $roleUid): void
    {
        $role = $this->findRole($roleUid);

        if ($role->is_system) {
            throw ValidationException::withMessages([
                'role_uid' => ['Los roles del sistema no se pueden eliminar'],
            ]);
        }

        $role->delete();
    }

    public function removeRoleFromUser(string $userUid, string $roleUid): array
    {
        $user = $this->findUser($userUid);
        $role = $this->findRole($roleUid);

        $user->removeRole($role);

        return $this->getUserAccess($userUid);
    }

    public function grantDirectPermissionToUser(string $userUid, string $permissionUid): array
    {
        $user = $this->findUser($userUid);
        $permission = $this->findPermission($permissionUid);
        $this->ensurePermissionAllowedForUserTenant(collect([$permission]), $user);

        $user->givePermissionTo($permission);

        return $this->getUserAccess($userUid);
    }

    public function revokeDirectPermissionFromUser(string $userUid, string $permissionUid): array
    {
        $user = $this->findUser($userUid);
        $permission = $this->findPermission($permissionUid);

        $user->revokePermissionTo($permission);

        return $this->getUserAccess($userUid);
    }

    private function findUser(string $userUid): User
    {
        $query = $this->platformAdminIsActing()
            ? User::withoutGlobalScopes()
            : User::query();

        $user = $query->where('uid', $userUid)->first();

        if (! $user) {
            throw new ModelNotFoundException('Usuario no encontrado');
        }

        return $user;
    }

    private function findRole(string $roleUid): Role
    {
        $query = $this->platformAdminIsActing()
            ? Role::withoutGlobalScopes()
            : Role::query();

        $role = $query->where('uid', $roleUid)->first();

        if (! $role) {
            throw ValidationException::withMessages([
                'role_uid' => ['El rol no existe o no pertenece a este tenant'],
            ]);
        }

        return $role;
    }

    private function findPermission(string $permissionUid): Permission
    {
        $permission = Permission::query()
            ->where('scope', Permission::SCOPE_TENANT)
            ->where('uid', $permissionUid)
            ->first();

        if (! $permission) {
            throw ValidationException::withMessages([
                'permission_uid' => ['El permiso no existe'],
            ]);
        }

        return $permission;
    }

    private function permissionIdsFromUids(array $permissionUids): array
    {
        if (empty($permissionUids)) {
            return [];
        }

        $permissions = Permission::query()
            ->where('scope', Permission::SCOPE_TENANT)
            ->whereIn('uid', $permissionUids)
            ->get();

        if ($permissions->count() !== count(array_unique($permissionUids))) {
            throw ValidationException::withMessages([
                'permission_uids' => ['Uno o mas permisos no existen'],
            ]);
        }

        $this->ensurePermissionAllowedForCurrentTenant($permissions);

        return $permissions->pluck('id')->all();
    }

    private function ensurePermissionAllowedForCurrentTenant($permissions): void
    {
        $user = auth()->user();

        if (! $user || $this->platformAdminIsActing() || ! $user->tenant) {
            return;
        }

        $this->planPermissionService->assertPermissionsAllowedForTenant($permissions, $user->tenant);
    }

    private function ensurePermissionAllowedForUserTenant($permissions, User $targetUser): void
    {
        if ($this->platformAdminIsActing() || ! $targetUser->tenant) {
            return;
        }

        $this->planPermissionService->assertPermissionsAllowedForTenant($permissions, $targetUser->tenant);
    }

    private function ensureRoleKeyIsAvailable(string $key, ?int $ignoreRoleId = null): void
    {
        $query = $this->platformAdminIsActing()
            ? Role::withoutGlobalScopes()
            : Role::query();

        $exists = $query
            ->where('key', $key)
            ->when($ignoreRoleId, fn ($query) => $query->where('id', '!=', $ignoreRoleId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'key' => ['Ya existe un rol con esta clave en el tenant'],
            ]);
        }
    }

    private function uniqueRoleKey(string $source, ?int $ignoreRoleId = null): string
    {
        $base = $this->normalizeRoleKey($source);
        $key = $base;
        $suffix = 2;

        while ($this->roleKeyExists($key, $ignoreRoleId)) {
            $key = Str::limit($base, 95 - strlen((string) $suffix), '').'_'.$suffix;
            $suffix++;
        }

        return $key;
    }

    private function normalizeRoleKey(string $source): string
    {
        $key = str_replace('-', '_', Str::slug($source, '_'));

        return $key !== '' ? $key : 'role';
    }

    private function roleKeyExists(string $key, ?int $ignoreRoleId = null): bool
    {
        $query = $this->platformAdminIsActing()
            ? Role::withoutGlobalScopes()
            : Role::query();

        return $query
            ->where('key', $key)
            ->when($ignoreRoleId, fn ($query) => $query->where('id', '!=', $ignoreRoleId))
            ->exists();
    }

    private function platformAdminIsActing(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->is_platform_admin && $user->tenant_id === null);
    }
}
