<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminRole;
use App\Models\Permission;
use App\Support\ApiIndex;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminPlatformRoleController extends Controller
{
    public function index(Request $request)
    {
        $query = AdminRole::query()->withCount('users');

        $search = $request->get('search');
        if (! empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('key', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        return $this->successResponse(
            ApiIndex::paginateOrGet($query->latest(), $request->query(), 'admin_roles_page')
        );
    }

    public function show(string $uid)
    {
        $role = AdminRole::query()->with('permissions')->where('uid', $uid)->firstOrFail();

        return $this->successResponse($role);
    }

    public function store(Request $request)
    {
        $validated = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'key' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'permission_uids' => 'nullable|array',
            'permission_uids.*' => [
                'uuid',
                Rule::exists('permissions', 'uid')->where('scope', Permission::SCOPE_PLATFORM),
            ],
        ])->validate();

        $role = AdminRole::query()->create([
            'name' => $validated['name'],
            'key' => $this->uniqueRoleKey($validated['key'] ?? $validated['name']),
            'description' => $validated['description'] ?? null,
        ]);

        if (! empty($validated['permission_uids'])) {
            $permissionIds = $this->platformPermissionIds($validated['permission_uids']);
            $role->permissions()->sync($permissionIds);
        }

        return $this->successResponse($role->load('permissions'), 201, 'Rol de plataforma creado');
    }

    public function update(Request $request, string $uid)
    {
        $role = AdminRole::query()->where('uid', $uid)->firstOrFail();

        if ($role->is_system) {
            return $this->errorResponse('No puedes modificar un rol del sistema', 422);
        }

        $validated = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'key' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'permission_uids' => 'nullable|array',
            'permission_uids.*' => [
                'uuid',
                Rule::exists('permissions', 'uid')->where('scope', Permission::SCOPE_PLATFORM),
            ],
        ])->validate();

        if (array_key_exists('key', $validated) && $validated['key'] !== null) {
            $validated['key'] = $this->uniqueRoleKey($validated['key'], $role->getKey());
        } elseif (array_key_exists('key', $validated)) {
            unset($validated['key']);
        }

        $role->update([
            'name' => $validated['name'] ?? $role->name,
            'key' => $validated['key'] ?? $role->key,
            'description' => array_key_exists('description', $validated) ? $validated['description'] : $role->description,
        ]);

        if (array_key_exists('permission_uids', $validated)) {
            $permissionIds = $this->platformPermissionIds($validated['permission_uids']);
            $role->permissions()->sync($permissionIds);
        }

        return $this->successResponse($role->load('permissions'), 200, 'Rol de plataforma actualizado');
    }

    public function destroy(string $uid)
    {
        $role = AdminRole::query()->where('uid', $uid)->firstOrFail();

        if ($role->is_system) {
            return $this->errorResponse('No puedes eliminar un rol del sistema', 422);
        }

        $role->delete();

        return $this->successResponse(null, 200, 'Rol de plataforma eliminado');
    }

    public function permissions()
    {
        return $this->successResponse(
            Permission::query()
                ->where('scope', Permission::SCOPE_PLATFORM)
                ->orderBy('module')
                ->orderBy('action')
                ->get()
        );
    }

    private function platformPermissionIds(array $permissionUids)
    {
        $permissions = Permission::query()
            ->where('scope', Permission::SCOPE_PLATFORM)
            ->whereIn('uid', array_unique($permissionUids))
            ->get();

        if ($permissions->count() !== count(array_unique($permissionUids))) {
            throw ValidationException::withMessages([
                'permission_uids' => ['Todos los permisos deben pertenecer al alcance de plataforma'],
            ]);
        }

        return $permissions->pluck('id');
    }

    private function uniqueRoleKey(string $source, ?int $ignoreRoleId = null): string
    {
        $base = $this->normalizeRoleKey($source);
        $key = $base;
        $suffix = 2;

        while (
            AdminRole::query()
                ->where('key', $key)
                ->when($ignoreRoleId, fn ($query) => $query->whereKeyNot($ignoreRoleId))
                ->exists()
        ) {
            $key = Str::limit($base, 95 - strlen((string) $suffix), '').'_'.$suffix;
            $suffix++;
        }

        return $key;
    }

    private function normalizeRoleKey(string $source): string
    {
        $key = str_replace('-', '_', Str::slug($source, '_'));

        return $key !== '' ? $key : 'platform_role';
    }
}
