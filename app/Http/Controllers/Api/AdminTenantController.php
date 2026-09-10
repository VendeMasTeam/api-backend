<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OpportunityStageProvisioner;
use App\Services\PlanPermissionService;
use App\Services\TenantRoleProvisioner;
use App\Services\TenantSchemaService;
use App\Services\TwoFactorService;
use App\Support\ApiIndex;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminTenantController extends Controller
{
    public function index(Request $request)
    {
        $validated = Validator::make($request->query(), [
            'search' => 'nullable|string|max:255',
            'plan_uid' => 'nullable|uuid',
            'estado' => 'nullable|string|in:ACTIVO,TRIAL,VENCIDO,SUSPENDIDO,INACTIVO,ARCHIVADO',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'sort_by' => 'nullable|string|in:nombre,mrr,totalUsuarios,creadoEn,ultimoAcceso',
            'sort_dir' => 'nullable|string|in:asc,desc',
        ])->validate();

        $query = Tenant::query()
            ->with('plan')
            ->withCount([
                'users as total_usuarios' => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->withMax([
                'users as last_access_at' => fn ($q) => $q->withoutGlobalScopes(),
            ], 'last_login_at');

        if (!empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('name', 'like', '%' . $search . '%')
                    ->orWhere('domain', 'like', '%' . $search . '%');
            });
        }

        if (!empty($validated['plan_uid'])) {
            $planId = Plan::query()->where('uid', $validated['plan_uid'])->value('id');
            $query->where('plan_id', $planId);
        }

        if (!empty($validated['estado'])) {
            $query->where('status', $validated['estado']);
        }

        $sortMap = [
            'nombre' => 'name',
            'mrr' => 'mrr',
            'totalUsuarios' => 'total_usuarios',
            'creadoEn' => 'created_at',
            'ultimoAcceso' => 'last_access_at',
        ];

        $sortBy = $sortMap[$validated['sort_by'] ?? 'creadoEn'] ?? 'created_at';
        $sortDir = $validated['sort_dir'] ?? 'desc';

        $query->orderBy($sortBy, $sortDir);

        $result = ApiIndex::paginateOrGet($query, $validated, 'tenants_page');

        if ($result instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator) {
            $result->setCollection($result->getCollection()->map(fn (Tenant $tenant) => $this->serializeTenant($tenant)));

            return $this->successResponse($result);
        }

        return $this->successResponse(
            collect($result)->map(fn (Tenant $tenant) => $this->serializeTenant($tenant))->values()
        );
    }

    public function store(
        Request $request,
        TenantRoleProvisioner $roleProvisioner,
        OpportunityStageProvisioner $stageProvisioner,
        TenantSchemaService $tenantSchemaService
    )
    {
        try {
            $validated = $request->validate([
                'nombre' => 'required|string|max:255',
                'dominio' => 'required|string|max:255|unique:tenants,domain',
                'pais' => 'nullable|string|max:120',
                'email_contacto' => 'nullable|email|max:255',
                'plan_uid' => 'nullable|uuid',
                'estado' => 'required|string|in:ACTIVO,TRIAL,VENCIDO,SUSPENDIDO,INACTIVO,ARCHIVADO',
                'mrr' => 'nullable|numeric|min:0',
                'almacenamiento_usado_gb' => 'nullable|numeric|min:0',
                'limite_almacenamiento_gb' => 'nullable|numeric|min:0',
            ]);

            if ($this->tenantNameExists($validated['nombre'])) {
                return $this->errorResponse('Validation error', 422, [
                    'nombre' => ['Ya existe un tenant con este nombre'],
                ]);
            }

            $plan = !empty($validated['plan_uid'])
                ? Plan::query()->where('uid', $validated['plan_uid'])->first()
                : null;

            if (!empty($validated['plan_uid']) && !$plan) {
                return $this->errorResponse('Validation error', 422, [
                    'plan_uid' => ['El plan no existe'],
                ]);
            }

            $tenant = DB::transaction(function () use ($validated, $plan, $roleProvisioner, $tenantSchemaService) {
                $tenant = Tenant::query()->create([
                    'name' => $validated['nombre'],
                    'domain' => $validated['dominio'],
                    'country' => $validated['pais'] ?? null,
                    'contact_email' => $validated['email_contacto'] ?? null,
                    'plan_id' => $plan?->getKey(),
                    'status' => $validated['estado'],
                    'mrr' => $validated['mrr'] ?? (float) ($plan?->price ?? 0),
                    'storage_used_gb' => $validated['almacenamiento_usado_gb'] ?? 0,
                    'storage_limit_gb' => $validated['limite_almacenamiento_gb'] ?? data_get($plan?->features, 'storage_gb'),
                    'is_active' => in_array($validated['estado'], ['ACTIVO', 'TRIAL'], true),
                    'expires_at' => $validated['estado'] === 'VENCIDO' ? now() : null,
                ]);

                $roleProvisioner->provision($tenant);
                $tenantSchemaService->provision($tenant);

                return $tenant;
            });

            $tenant = $tenantSchemaService->bootstrapTenant(
                $tenant,
                fn (Tenant $tenant, bool $usingSchema) => $stageProvisioner->provision($tenant)
            );

            return $this->successResponse($this->serializeTenant($tenant->fresh('plan')), 201, 'Tenant creado');
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation error', 422, $e->errors());
        } catch (\Throwable $e) {
            return $this->errorResponse('Server error', 500, ['server' => [$e->getMessage()]]);
        }
    }

    public function show(string $uid)
    {
        $tenant = Tenant::query()
            ->with('plan')
            ->withCount([
                'users as total_usuarios' => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->withMax([
                'users as last_access_at' => fn ($q) => $q->withoutGlobalScopes(),
            ], 'last_login_at')
            ->where('uid', $uid)
            ->first();

        if (!$tenant) {
            return $this->errorResponse('Tenant no encontrado', 404);
        }

        return $this->successResponse($this->serializeTenant($tenant));
    }

    public function update(Request $request, string $uid)
    {
        try {
            $tenant = Tenant::query()->where('uid', $uid)->first();

            if (!$tenant) {
                return $this->errorResponse('Tenant no encontrado', 404);
            }

            $validated = $request->validate([
                'nombre' => 'sometimes|string|max:255',
                'dominio' => ['sometimes', 'string', 'max:255', Rule::unique('tenants', 'domain')->ignore($tenant->id)],
                'pais' => 'sometimes|nullable|string|max:120',
                'email_contacto' => 'sometimes|nullable|email|max:255',
                'plan_uid' => 'sometimes|nullable|uuid',
                'estado' => 'sometimes|string|in:ACTIVO,TRIAL,VENCIDO,SUSPENDIDO,INACTIVO,ARCHIVADO',
                'mrr' => 'sometimes|numeric|min:0',
                'almacenamiento_usado_gb' => 'sometimes|numeric|min:0',
                'limite_almacenamiento_gb' => 'sometimes|nullable|numeric|min:0',
            ]);

            if (array_key_exists('nombre', $validated) && $this->tenantNameExists($validated['nombre'], $tenant->id)) {
                return $this->errorResponse('Validation error', 422, [
                    'nombre' => ['Ya existe un tenant con este nombre'],
                ]);
            }

            $plan = array_key_exists('plan_uid', $validated) && !empty($validated['plan_uid'])
                ? Plan::query()->where('uid', $validated['plan_uid'])->first()
                : null;

            if (array_key_exists('plan_uid', $validated) && !empty($validated['plan_uid']) && !$plan) {
                return $this->errorResponse('Validation error', 422, [
                    'plan_uid' => ['El plan no existe'],
                ]);
            }

            $status = $validated['estado'] ?? $tenant->status;

            $tenant->update([
                'name' => $validated['nombre'] ?? $tenant->name,
                'domain' => $validated['dominio'] ?? $tenant->domain,
                'country' => $validated['pais'] ?? $tenant->country,
                'contact_email' => array_key_exists('email_contacto', $validated) ? $validated['email_contacto'] : $tenant->contact_email,
                'plan_id' => array_key_exists('plan_uid', $validated) ? $plan?->getKey() : $tenant->plan_id,
                'status' => $status,
                'mrr' => $validated['mrr'] ?? $tenant->mrr,
                'storage_used_gb' => $validated['almacenamiento_usado_gb'] ?? $tenant->storage_used_gb,
                'storage_limit_gb' => array_key_exists('limite_almacenamiento_gb', $validated) ? $validated['limite_almacenamiento_gb'] : $tenant->storage_limit_gb,
                'is_active' => in_array($status, ['ACTIVO', 'TRIAL'], true),
                'expires_at' => $status === 'VENCIDO' ? now() : ($status === 'ACTIVO' ? null : $tenant->expires_at),
            ]);

            return $this->successResponse($this->serializeTenant($tenant->fresh('plan')), 200, 'Tenant actualizado');
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation error', 422, $e->errors());
        } catch (\Throwable $e) {
            return $this->errorResponse('Server error', 500, ['server' => [$e->getMessage()]]);
        }
    }

    public function suspend(string $uid)
    {
        $tenant = Tenant::query()->where('uid', $uid)->first();

        if (!$tenant) {
            return $this->errorResponse('Tenant no encontrado', 404);
        }

        $tenant->update([
            'status' => 'SUSPENDIDO',
            'is_active' => false,
        ]);

        return $this->successResponse($this->serializeTenant($tenant->fresh('plan')), 200, 'Tenant suspendido');
    }

    public function activate(string $uid)
    {
        $tenant = Tenant::query()->where('uid', $uid)->first();

        if (!$tenant) {
            return $this->errorResponse('Tenant no encontrado', 404);
        }

        $tenant->update([
            'status' => 'ACTIVO',
            'is_active' => true,
            'expires_at' => null,
        ]);

        return $this->successResponse($this->serializeTenant($tenant->fresh('plan')), 200, 'Tenant activado');
    }

    public function archive(string $uid)
    {
        $tenant = Tenant::query()->where('uid', $uid)->first();

        if (!$tenant) {
            return $this->errorResponse('Tenant no encontrado', 404);
        }

        $tenant->update([
            'status' => 'ARCHIVADO',
            'is_active' => false,
        ]);

        return $this->successResponse($this->serializeTenant($tenant->fresh('plan')), 200, 'Tenant archivado');
    }

    public function destroy(string $uid)
    {
        $tenant = Tenant::query()->where('uid', $uid)->first();

        if (!$tenant) {
            return $this->errorResponse('Tenant no encontrado', 404);
        }

        DB::transaction(function () use ($tenant) {
            User::withoutGlobalScopes()
                ->where('tenant_id', $tenant->getKey())
                ->get()
                ->each(function (User $user) {
                    $user->tokens()->delete();
                    $user->forceFill([
                        'locked_until' => now()->addYears(100),
                        'failed_login_attempts' => 0,
                    ])->save();
                });

            $tenant->forceFill([
                'status' => 'ARCHIVADO',
                'is_active' => false,
                'expires_at' => now(),
            ])->save();
        });

        return $this->successResponse($this->serializeTenant($tenant->fresh('plan')), 200, 'Tenant eliminado');
    }

    public function restore(string $uid)
    {
        $tenant = Tenant::query()->where('uid', $uid)->first();

        if (!$tenant) {
            return $this->errorResponse('Tenant no encontrado', 404);
        }

        $tenant->update([
            'status' => 'ACTIVO',
            'is_active' => true,
            'expires_at' => null,
        ]);

        return $this->successResponse($this->serializeTenant($tenant->fresh('plan')), 200, 'Tenant restaurado');
    }

    public function createUser(Request $request, string $uid, TenantRoleProvisioner $roleProvisioner)
    {
        try {
            $tenant = Tenant::query()->where('uid', $uid)->first();

            if (!$tenant) {
                return $this->errorResponse('Tenant no encontrado', 404);
            }

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:users,email',
                'role' => 'nullable|string|in:owner,manager,seller',
            ]);

            $user = DB::transaction(function () use ($tenant, $validated, $roleProvisioner) {
                $roleProvisioner->provision($tenant);

                $user = User::withoutGlobalScopes()->create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'password' => Hash::make(\Illuminate\Support\Str::random(24)),
                    'tenant_id' => $tenant->getKey(),
                    'is_platform_admin' => false,
                ]);

                $roleKey = $validated['role'] ?? 'owner';
                $role = Role::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->getKey())
                    ->where('key', $roleKey)
                    ->first();

                if ($role) {
                    DB::table('role_user')->updateOrInsert([
                        'role_id' => $role->getKey(),
                        'user_id' => $user->getKey(),
                    ], [
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                return $user->fresh();
            });

            $resetEmailQueued = $this->sendTenantUserResetEmailAfterResponse($user, $tenant);

            return $this->successResponse([
                'uid' => $user->uid,
                'name' => $user->name,
                'email' => $user->email,
                'tenant_uid' => $tenant->uid,
                'roles' => [$validated['role'] ?? 'owner'],
                'reset_email_sent' => $resetEmailQueued,
                'reset_email_queued' => $resetEmailQueued,
            ], 201, 'Usuario administrador del tenant creado');
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation error', 422, $e->errors());
        } catch (\Throwable $e) {
            return $this->errorResponse('Server error', 500, ['server' => [$e->getMessage()]]);
        }
    }

    public function users(Request $request, string $uid)
    {
        $validated = Validator::make($request->query(), [
            'role' => 'nullable|string|in:owner,manager,seller',
            'search' => 'nullable|string|max:255',
            'estado' => 'nullable|string|in:ACTIVO,INACTIVO,active,inactive',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ])->validate();

        $tenant = Tenant::query()->where('uid', $uid)->first();

        if (!$tenant) {
            return $this->errorResponse('Tenant no encontrado', 404);
        }

        $users = User::withoutGlobalScopes()
            ->with(['roles' => fn ($query) => $query->withoutGlobalScopes()])
            ->where('tenant_id', $tenant->getKey())
            ->when(!empty($validated['search']), function ($query) use ($validated) {
                $search = $validated['search'];

                $query->where(function ($builder) use ($search) {
                    $builder
                        ->where('name', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%');
                });
            })
            ->when(!empty($validated['estado']), function ($query) use ($validated) {
                if (in_array($validated['estado'], ['ACTIVO', 'active'], true)) {
                    $query->where(function ($builder) {
                        $builder->whereNull('locked_until')->orWhere('locked_until', '<=', now());
                    });
                }

                if (in_array($validated['estado'], ['INACTIVO', 'inactive'], true)) {
                    $query->where('locked_until', '>', now());
                }
            })
            ->when(!empty($validated['role']), function ($query) use ($validated, $tenant) {
                $query->whereHas('roles', function ($roleQuery) use ($validated, $tenant) {
                    $roleQuery
                        ->withoutGlobalScopes()
                        ->where('tenant_id', $tenant->getKey())
                        ->where('key', $validated['role']);
                });
            })
            ->orderBy('name')
            ->paginate(
                ApiIndex::perPage($validated),
                ['*'],
                'users_page',
                ApiIndex::page($validated)
            );

        $users->setCollection(
            $users->getCollection()
                ->map(fn (User $user) => $this->serializeTenantUser($user))
                ->values()
        );

        return $this->successResponse($users);
    }

    public function updateUser(Request $request, string $uid, string $userUid, TenantRoleProvisioner $roleProvisioner)
    {
        try {
            $tenant = Tenant::query()->where('uid', $uid)->first();

            if (!$tenant) {
                return $this->errorResponse('Tenant no encontrado', 404);
            }

            $user = $this->findTenantUser($tenant, $userUid);

            if (!$user) {
                return $this->errorResponse('Usuario no encontrado', 404);
            }

            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user->id)],
                'password' => 'sometimes|nullable|string|min:8',
                'role' => 'sometimes|string|in:owner,manager,seller',
                'is_active' => 'sometimes|boolean',
                'active' => 'sometimes|boolean',
                'status' => 'sometimes|string|in:ACTIVO,INACTIVO,active,inactive',
            ]);

            $willDeactivate = $this->payloadDeactivatesUser($validated);
            $willRemoveOwnerRole = array_key_exists('role', $validated)
                && $user->roles->first()?->key === 'owner'
                && $validated['role'] !== 'owner';

            if (($willDeactivate || $willRemoveOwnerRole) && $this->isLastActiveOwner($tenant, $user)) {
                return $this->errorResponse('Validation error', 422, [
                    'user' => ['No puedes dejar el tenant sin un owner activo'],
                ]);
            }

            DB::transaction(function () use ($tenant, $user, $validated, $roleProvisioner) {
                $payload = [];

                foreach (['name', 'email'] as $field) {
                    if (array_key_exists($field, $validated)) {
                        $payload[$field] = $validated[$field];
                    }
                }

                if (!empty($validated['password'])) {
                    $payload['password'] = Hash::make($validated['password']);
                    $payload['failed_login_attempts'] = 0;
                    $payload['locked_until'] = null;
                }

                if ($this->payloadActivatesUser($validated)) {
                    $payload['locked_until'] = null;
                    $payload['failed_login_attempts'] = 0;
                }

                if ($this->payloadDeactivatesUser($validated)) {
                    $payload['locked_until'] = now()->addYears(100);
                }

                if ($payload !== []) {
                    $user->forceFill($payload)->save();
                }

                if (array_key_exists('role', $validated)) {
                    $roleProvisioner->provision($tenant);

                    $role = Role::withoutGlobalScopes()
                        ->where('tenant_id', $tenant->getKey())
                        ->where('key', $validated['role'])
                        ->firstOrFail();

                    $user->roles()->sync([$role->getKey()]);
                }

                if ($this->payloadDeactivatesUser($validated)) {
                    $user->tokens()->delete();
                }
            });

            return $this->successResponse($this->serializeTenantUser($this->freshTenantUser($user)), 200, 'Usuario actualizado');
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation error', 422, $e->errors());
        } catch (\Throwable $e) {
            return $this->errorResponse('Server error', 500, ['server' => [$e->getMessage()]]);
        }
    }

    public function destroyUser(string $uid, string $userUid)
    {
        $tenant = Tenant::query()->where('uid', $uid)->first();

        if (!$tenant) {
            return $this->errorResponse('Tenant no encontrado', 404);
        }

        $user = $this->findTenantUser($tenant, $userUid);

        if (!$user) {
            return $this->errorResponse('Usuario no encontrado', 404);
        }

        if ($this->isLastActiveOwner($tenant, $user)) {
            return $this->errorResponse('Validation error', 422, [
                'user' => ['No puedes eliminar el ultimo owner activo del tenant'],
            ]);
        }

        $user->tokens()->delete();
        $user->forceFill([
            'locked_until' => now()->addYears(100),
            'failed_login_attempts' => 0,
        ])->save();

        return $this->successResponse($this->serializeTenantUser($this->freshTenantUser($user)), 200, 'Usuario eliminado');
    }

    private function sendTenantUserResetEmailAfterResponse(User $user, Tenant $tenant): bool
    {
        if (app()->environment('testing')) {
            return $this->deliverTenantUserResetEmail($user, $tenant);
        }

        app()->terminating(fn () => $this->deliverTenantUserResetEmail($user, $tenant));

        return true;
    }

    private function deliverTenantUserResetEmail(User $user, Tenant $tenant): bool
    {
        try {
            $freshUser = User::withoutGlobalScopes()->whereKey($user->getKey())->first();

            if (! $freshUser) {
                return false;
            }

            $token = Password::broker()->createToken($freshUser);
            $freshUser->sendPasswordResetNotification($token);

            return true;
        } catch (\Throwable $e) {
            Log::warning('No se pudo enviar el reset password al crear usuario de tenant', [
                'tenant_uid' => $tenant->uid,
                'user_uid' => $user->uid,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function lockUser(string $uid, string $userUid)
    {
        $tenant = Tenant::query()->where('uid', $uid)->first();

        if (!$tenant) {
            return $this->errorResponse('Tenant no encontrado', 404);
        }

        $user = $this->findTenantUser($tenant, $userUid);

        if (!$user) {
            return $this->errorResponse('Usuario no encontrado', 404);
        }

        $user->tokens()->delete();
        $user->update([
            'locked_until' => now()->addYears(100),
        ]);

        return $this->successResponse($this->serializeTenantUser($this->freshTenantUser($user)), 200, 'Usuario bloqueado');
    }

    public function unlockUser(string $uid, string $userUid)
    {
        $tenant = Tenant::query()->where('uid', $uid)->first();

        if (!$tenant) {
            return $this->errorResponse('Tenant no encontrado', 404);
        }

        $user = $this->findTenantUser($tenant, $userUid);

        if (!$user) {
            return $this->errorResponse('Usuario no encontrado', 404);
        }

        $user->update([
            'locked_until' => null,
            'failed_login_attempts' => 0,
        ]);

        return $this->successResponse($this->serializeTenantUser($this->freshTenantUser($user)), 200, 'Usuario desbloqueado');
    }

    public function resetUserTwoFactor(string $uid, string $userUid, TwoFactorService $twoFactorService)
    {
        $tenant = Tenant::query()->where('uid', $uid)->first();

        if (!$tenant) {
            return $this->errorResponse('Tenant no encontrado', 404);
        }

        $user = $this->findTenantUser($tenant, $userUid);

        if (!$user) {
            return $this->errorResponse('Usuario no encontrado', 404);
        }

        $user = $this->freshTenantUser($twoFactorService->disableForUser($user));

        return $this->successResponse($this->serializeTenantUser($user), 200, '2FA reseteado correctamente');
    }

    public function permissions(Request $request, string $uid, PlanPermissionService $planPermissionService)
    {
        $validated = Validator::make($request->query(), [
            'only_active_modules' => 'nullable|string|in:true,false,1,0',
        ])->validate();

        $tenant = Tenant::query()->with('plan')->where('uid', $uid)->first();

        if (!$tenant) {
            return $this->errorResponse('Tenant no encontrado', 404);
        }

        $permissions = Permission::query()
            ->where('module', '!=', 'admin')
            ->orderBy('module')
            ->orderBy('action')
            ->get();

        $onlyActiveModules = in_array(strtolower((string) ($validated['only_active_modules'] ?? 'false')), ['true', '1'], true);

        if ($onlyActiveModules) {
            $permissions = $planPermissionService->filterPermissionsForTenant($permissions, $tenant);
        }

        return $this->successResponse($permissions);
    }

    private function serializeTenant(Tenant $tenant): array
    {
        $totalUsers = $tenant->total_usuarios
            ?? User::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->count();

        $lastAccessAt = $tenant->last_access_at
            ?? User::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->max('last_login_at');

        return [
            'uid' => $tenant->uid,
            'nombre' => $tenant->name,
            'dominio' => $tenant->domain,
            'schema_name' => $tenant->schema_name,
            'schema_migrated_at' => optional($tenant->schema_migrated_at)?->toISOString(),
            'schema_ready' => $tenant->hasMigratedSchema(),
            'pais' => $tenant->country,
            'email_contacto' => $tenant->contact_email,
            'plan_uid' => $tenant->plan?->uid,
            'plan_nombre' => $tenant->plan?->name,
            'mrr' => (float) $tenant->mrr,
            'estado' => $tenant->status,
            'expires_at' => optional($tenant->expires_at)?->toISOString(),
            'total_usuarios' => (int) $totalUsers,
            'limite_usuarios' => $tenant->plan?->max_users,
            'almacenamiento_usado_gb' => (float) $tenant->storage_used_gb,
            'limite_almacenamiento_gb' => $tenant->storage_limit_gb !== null ? (float) $tenant->storage_limit_gb : null,
            'api_calls_mes' => (int) ($tenant->api_calls_mes ?? 0),
            'limite_api_calls' => (int) (($tenant->limite_api_calls ?? 0) ?: data_get($tenant->plan?->features, 'api_calls_month', 0)),
            'created_at' => optional($tenant->created_at)?->toISOString(),
            'last_access_at' => $lastAccessAt ? Carbon::parse($lastAccessAt)->toISOString() : null,
        ];
    }

    private function serializeTenantUser(User $user): array
    {
        return [
            'uid' => $user->uid,
            'name' => $user->name,
            'email' => $user->email,
            'rol' => $user->roles->first()?->key,
            'ultimo_acceso' => $user->last_login_at?->toISOString(),
            'estado' => $user->isLocked() ? 'Inactivo' : 'Activo',
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
        ];
    }

    private function findTenantUser(Tenant $tenant, string $userUid): ?User
    {
        return User::withoutGlobalScopes()
            ->with(['roles' => fn ($query) => $query->withoutGlobalScopes()])
            ->where('tenant_id', $tenant->getKey())
            ->where('uid', $userUid)
            ->where('is_platform_admin', false)
            ->first();
    }

    private function freshTenantUser(User $user): User
    {
        return User::withoutGlobalScopes()
            ->with(['roles' => fn ($query) => $query->withoutGlobalScopes()])
            ->whereKey($user->getKey())
            ->firstOrFail();
    }

    private function payloadActivatesUser(array $payload): bool
    {
        return ($payload['is_active'] ?? null) === true
            || ($payload['active'] ?? null) === true
            || in_array($payload['status'] ?? null, ['ACTIVO', 'active'], true);
    }

    private function payloadDeactivatesUser(array $payload): bool
    {
        return ($payload['is_active'] ?? null) === false
            || ($payload['active'] ?? null) === false
            || in_array($payload['status'] ?? null, ['INACTIVO', 'inactive'], true);
    }

    private function isLastActiveOwner(Tenant $tenant, User $user): bool
    {
        if ($user->roles->first()?->key !== 'owner' || $user->isLocked()) {
            return false;
        }

        return User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('is_platform_admin', false)
            ->where(function ($query) {
                $query->whereNull('locked_until')->orWhere('locked_until', '<=', now());
            })
            ->whereHas('roles', function ($query) use ($tenant) {
                $query
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $tenant->getKey())
                    ->where('key', 'owner');
            })
            ->count() <= 1;
    }

    private function tenantNameExists(string $name, ?int $ignoreTenantId = null): bool
    {
        $normalizedName = mb_strtolower(trim($name));

        $query = Tenant::query()
            ->whereRaw('lower(name) = ?', [$normalizedName]);

        if ($ignoreTenantId !== null) {
            $query->whereKeyNot($ignoreTenantId);
        }

        return $query->exists();
    }
}
