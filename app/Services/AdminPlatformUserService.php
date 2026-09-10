<?php

namespace App\Services;

use App\Models\AdminRole;
use App\Models\User;
use App\Support\ApiIndex;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminPlatformUserService
{
    public function __construct(
        private readonly TwoFactorService $twoFactorService
    ) {
    }

    public function list(array $filters = [])
    {
        $query = User::query()
            ->withoutGlobalScopes()
            ->where('is_platform_admin', true)
            ->whereNull('tenant_id')
            ->with('adminRoles')
            ->latest();

        $search = $filters['search'] ?? null;
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                  ->orWhere('email', 'like', '%' . $search . '%');
            });
        }

        $roleUid = $filters['admin_role_uid'] ?? null;
        if (!empty($roleUid)) {
            $roleId = AdminRole::query()->where('uid', $roleUid)->value('id');
            $query->whereHas('adminRoles', fn ($q) => $q->where('admin_role_id', $roleId));
        }

        return ApiIndex::paginateOrGet($query, $filters, 'admin_users_page');
    }

    public function findByUid(string $uid): User
    {
        return User::query()
            ->withoutGlobalScopes()
            ->where('is_platform_admin', true)
            ->whereNull('tenant_id')
            ->with('adminRoles.permissions')
            ->where('uid', $uid)
            ->firstOrFail();
    }

    public function create(array $data): User
    {
        $validated = Validator::make($data, [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
            'admin_role_uids' => 'nullable|array',
            'admin_role_uids.*' => 'uuid|exists:admin_roles,uid',
        ])->validate();

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'is_platform_admin' => true,
            'tenant_id' => null,
        ]);

        if (!empty($validated['admin_role_uids'])) {
            $roleIds = AdminRole::query()->whereIn('uid', $validated['admin_role_uids'])->pluck('id');
            $user->adminRoles()->sync($roleIds);
        }

        return $user->fresh()->load('adminRoles');
    }

    public function update(string $uid, array $data): User
    {
        $user = $this->findByUid($uid);

        $validated = Validator::make($data, [
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => 'sometimes|string|min:8',
            'admin_role_uids' => 'nullable|array',
            'admin_role_uids.*' => 'uuid|exists:admin_roles,uid',
        ])->validate();

        $payload = [];
        foreach (['name', 'email'] as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = $validated[$field];
            }
        }

        if (array_key_exists('password', $validated)) {
            $payload['password'] = Hash::make($validated['password']);
        }

        if ($payload !== []) {
            $user->update($payload);
        }

        if (array_key_exists('admin_role_uids', $validated)) {
            $roleIds = AdminRole::query()->whereIn('uid', $validated['admin_role_uids'])->pluck('id');
            $user->adminRoles()->sync($roleIds);
        }

        return $user->fresh()->load('adminRoles.permissions');
    }

    public function assignRole(string $userUid, string $roleUid): User
    {
        $user = $this->findByUid($userUid);
        $role = AdminRole::query()->where('uid', $roleUid)->firstOrFail();

        $user->assignAdminRole($role);

        return $user->fresh()->load('adminRoles.permissions');
    }

    public function removeRole(string $userUid, string $roleUid): User
    {
        $user = $this->findByUid($userUid);
        $role = AdminRole::query()->where('uid', $roleUid)->firstOrFail();

        $user->removeAdminRole($role);

        return $user->fresh()->load('adminRoles.permissions');
    }

    public function resetTwoFactor(string $uid): User
    {
        $user = $this->findByUid($uid);

        return $this->twoFactorService->disableForUser($user)->fresh()->load('adminRoles.permissions');
    }
}
