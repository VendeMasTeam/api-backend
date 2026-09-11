<?php

namespace App\Services;

use App\Models\AdminRole;
use App\Models\User;
use App\Support\ApiIndex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
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

    public function lock(string $uid, User $actor): User
    {
        $user = $this->findByUid($uid);

        if ($user->is($actor)) {
            throw ValidationException::withMessages([
                'user' => ['No puedes desactivar tu propio usuario de plataforma'],
            ]);
        }

        if (! $user->isLocked() && $this->activePlatformAdminCount() <= 1) {
            throw ValidationException::withMessages([
                'user' => ['No puedes desactivar el ultimo superadmin activo'],
            ]);
        }

        DB::transaction(function () use ($user) {
            $this->deleteUserTokens([$user->getKey()]);

            $user->forceFill([
                'locked_until' => now()->addYears(100),
                'failed_login_attempts' => 0,
            ])->save();
        });

        return $user->fresh()->load('adminRoles.permissions');
    }

    public function unlock(string $uid): User
    {
        $user = $this->findByUid($uid);

        $user->forceFill([
            'locked_until' => null,
            'failed_login_attempts' => 0,
        ])->save();

        return $user->fresh()->load('adminRoles.permissions');
    }

    public function purge(string $uid, User $actor, array $data): array
    {
        $user = $this->findByUid($uid);

        $validated = Validator::make($data, [
            'confirmation' => 'required|string|max:255',
        ])->validate();

        if (trim($validated['confirmation']) !== $user->email) {
            throw ValidationException::withMessages([
                'confirmation' => ['La confirmacion debe coincidir exactamente con el email del usuario'],
            ]);
        }

        if ($user->is($actor)) {
            throw ValidationException::withMessages([
                'user' => ['No puedes eliminar definitivamente tu propio usuario de plataforma'],
            ]);
        }

        if (! $user->isLocked() && $this->activePlatformAdminCount() <= 1) {
            throw ValidationException::withMessages([
                'user' => ['No puedes eliminar definitivamente el ultimo superadmin activo'],
            ]);
        }

        $summary = DB::transaction(function () use ($user) {
            $tokensDeleted = $this->deleteUserTokens([$user->getKey()]);
            $supportSessionsDeleted = $this->deleteSupportSessionsForUser($user->getKey());
            $pivotRowsDeleted = $this->deleteUserPivots([$user->getKey()]);

            $user->delete();

            return [
                'tokens_deleted' => $tokensDeleted,
                'support_sessions_deleted' => $supportSessionsDeleted,
                'pivot_rows_deleted' => $pivotRowsDeleted,
            ];
        });

        return [
            'uid' => $uid,
            'email' => $user->email,
            ...$summary,
        ];
    }

    private function activePlatformAdminCount(): int
    {
        return User::withoutGlobalScopes()
            ->where('is_platform_admin', true)
            ->whereNull('tenant_id')
            ->where(function ($query) {
                $query->whereNull('locked_until')->orWhere('locked_until', '<=', now());
            })
            ->count();
    }

    private function deleteUserPivots(array $userIds): int
    {
        if ($userIds === []) {
            return 0;
        }

        $deleted = 0;

        foreach (['admin_role_user', 'permission_user', 'role_user'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'user_id')) {
                $deleted += DB::table($table)->whereIn('user_id', $userIds)->delete();
            }
        }

        return $deleted;
    }

    private function deleteSupportSessionsForUser(int $userId): int
    {
        if (! Schema::hasTable('support_sessions')) {
            return 0;
        }

        $tokenIds = DB::table('support_sessions')
            ->where('support_user_id', $userId)
            ->orWhere('impersonated_user_id', $userId)
            ->pluck('token_id')
            ->filter()
            ->all();

        $this->deleteTokensByIds($tokenIds);

        return DB::table('support_sessions')
            ->where('support_user_id', $userId)
            ->orWhere('impersonated_user_id', $userId)
            ->delete();
    }

    private function deleteUserTokens(array $userIds): int
    {
        if ($userIds === [] || ! Schema::hasTable('personal_access_tokens')) {
            return 0;
        }

        return DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $userIds)
            ->delete();
    }

    private function deleteTokensByIds(array $tokenIds): int
    {
        if ($tokenIds === [] || ! Schema::hasTable('personal_access_tokens')) {
            return 0;
        }

        return DB::table('personal_access_tokens')->whereIn('id', $tokenIds)->delete();
    }
}
